<?php
declare(strict_types=1);

// One-off: enrich data/products.csv with the Claude API -> data/products_enriched.json
// Usage: php scripts/enrich.php [--limit=N]   (resumable: re-run to fill in missing ids)

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config;

const BATCH_SIZE = 20;

/** Non-transient failure (a 4xx other than 429): the request itself is wrong, so stop the run. */
final class FatalError extends RuntimeException {}

const SYSTEM = 'You are a product-catalog analyst for an Israeli e-commerce store. You receive products '
    . '(mostly Hebrew names) and return structured enrichment data used for bilingual Hebrew/English search. '
    . 'Respond with a JSON array only: no prose, no markdown fences.';

const INSTRUCTIONS = <<<'TXT'
Enrich each product below. Return a JSON array with exactly one object per input product, in the same order, each with these keys:

- "id": the input id, a string like "P0003", copied unchanged
- "name_en": natural English product name, as a shopper would say it (keep brand and model; don't translate brand names)
- "transliterations": array of 1-4 strings: alternative ways an Israeli user might type the name in a search box. Prioritize, in this order:
  1. Brand names and loanwords in both scripts: Hebrew-script names -> Latin ("נינג'ה" -> "ninja"), Latin names -> Hebrew ("Air Fryer" -> "אייר פרייר"), plus the English word for Hebrew loanwords ("בלנדר" -> "blender").
  2. Common Hebrew spelling variants ("קיצ'ן אייד" / "קיטשן אייד").
  3. If the Hebrew name has a typo or non-standard spelling, the correct spelling users would type (e.g. "מטהג" -> "מטגן").
  Do NOT phonetically romanize native Hebrew words (e.g. "mikser omed mekzoi"): nobody searches that way. If nothing useful applies, return the single most likely alternative spelling or the English name; never an empty array.
- "category_en": short English category, 1-3 words (e.g. "Small Appliances")
- "uses": object {"he": [...], "en": [...]}, each with 3-5 short use cases or shopper intents, same meaning in both languages (e.g. "making smoothies", "gift for a coffee lover"). Max ~5 words each.
- "price": realistic retail price in ILS for the Israeli market, as a plain number (no currency symbol, no range, no string). Use typical Israeli retail endings (e.g. 49.90, 199, 1299). Estimate if unsure; never null.

Rules:
- Don't add keys. Don't skip products. Output must be valid JSON starting with "[" and ending with "]".

Products:
TXT;

function loadCsv(string $path): array
{
    $h = fopen($path, 'r') ?: throw new RuntimeException("Cannot open $path");
    $header = array_map(fn($c) => ltrim($c, "\xEF\xBB\xBF"), fgetcsv($h, 0, ',', '"', ''));
    $rows = [];
    while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) {
        if (count($r) === count($header)) {
            $row = array_combine($header, $r);
            $rows[$row['id']] = $row;
        }
    }
    fclose($h);
    return $rows;
}

function isStrList(mixed $v, int $min, int $max): bool
{
    return is_array($v) && array_is_list($v) && count($v) >= $min && count($v) <= $max
        && count(array_filter($v, fn($s) => is_string($s) && trim($s) !== '')) === count($v);
}

/** Returns validated enrichments keyed by id; throws on any problem. */
function enrichBatch(array $batch, string $key, string $model): array
{
    $input = array_map(fn($p) => array_diff_key($p, ['image_url' => 1]), array_values($batch));
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => ["x-api-key: $key", 'anthropic-version: 2023-06-01', 'content-type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $model, 'max_tokens' => 8000, 'system' => SYSTEM,
            'messages' => [['role' => 'user', 'content' => INSTRUCTIONS . "\n" . json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false) throw new RuntimeException('curl: ' . curl_error($ch));
    $resp = json_decode($body, true);
    if ($code !== 200) {
        $msg = "HTTP $code: " . substr($body, 0, 300);
        throw ($code === 429 || $code >= 500) ? new RuntimeException($msg) : new FatalError($msg);
    }
    if (($resp['stop_reason'] ?? '') === 'max_tokens') throw new RuntimeException('truncated (max_tokens)');

    $text = '';
    foreach ($resp['content'] ?? [] as $block) if (($block['type'] ?? '') === 'text') $text .= $block['text'] ?? '';
    $start = strpos($text, '[');
    $end = strrpos($text, ']');
    $items = $start !== false && $end > $start ? json_decode(substr($text, $start, $end - $start + 1), true) : null;
    if (!is_array($items) || !array_is_list($items)) {
        file_put_contents(dirname(__DIR__) . '/data/enrich_last_error.txt', $text);
        throw new RuntimeException('response is not a JSON array; starts with: ' . mb_substr($text, 0, 300));
    }

    $out = [];
    foreach ($items as $i) {
        $id = $i['id'] ?? null;
        if (!is_string($id) || !isset($batch[$id]) || isset($out[$id])) throw new RuntimeException('bad/duplicate id: ' . json_encode($id));
        $ok = is_string($i['name_en'] ?? null) && trim($i['name_en']) !== ''
            && is_string($i['category_en'] ?? null) && trim($i['category_en']) !== ''
            && isStrList($i['transliterations'] ?? null, 1, 8)
            && is_array($i['uses'] ?? null) && isStrList($i['uses']['he'] ?? null, 3, 5) && isStrList($i['uses']['en'] ?? null, 3, 5)
            && (is_int($i['price'] ?? null) || is_float($i['price'] ?? null)) && $i['price'] > 0;
        if (!$ok) throw new RuntimeException("invalid fields for $id");
        $out[$id] = array_intersect_key($i, array_flip(['name_en', 'transliterations', 'category_en', 'uses', 'price']));
    }
    if (count($out) !== count($batch)) throw new RuntimeException('missing ids in response');
    return $out;
}

$limit = null;
foreach ($argv as $a) if (preg_match('/^--limit=(\d+)$/', $a, $m)) $limit = (int)$m[1];

$key = Config::get('ANTHROPIC_API_KEY');
$model = Config::get('ANTHROPIC_MODEL');
$outFile = dirname(__DIR__) . '/data/products_enriched.json';

$products = loadCsv(dirname(__DIR__) . '/data/products.csv');
if ($limit !== null) $products = array_slice($products, 0, $limit, true);

$done = [];
if (is_file($outFile)) foreach (json_decode(file_get_contents($outFile), true) ?: [] as $p) $done[$p['id']] = $p;

$pending = array_diff_key($products, $done);
$batches = array_chunk($pending, BATCH_SIZE, true);
echo count($products) . " products in scope, " . count($done) . " already enriched in file, " . count($pending) . " to do\n";

foreach ($batches as $n => $batch) {
    for ($try = 1; $try <= 2; $try++) {
        try {
            foreach (enrichBatch($batch, $key, $model) as $id => $e) $done[$id] = $batch[$id] + $e;
            file_put_contents($outFile, json_encode(array_values($done), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            echo sprintf("Batch %d/%d ok (%d products)\n", $n + 1, count($batches), count($batch));
            continue 2;
        } catch (FatalError $e) {
            fwrite(STDERR, "Fatal, stopping run: {$e->getMessage()}\n");
            exit(1);
        } catch (RuntimeException $e) {
            echo sprintf("Batch %d/%d attempt %d failed: %s\n", $n + 1, count($batches), $try, $e->getMessage());
        }
    }
}

$missing = array_keys(array_diff_key($products, $done));
echo "\nEnriched this run: " . (count($pending) - count($missing)) . "\n";
echo "Total enriched in file: " . count($done) . "\n";
echo $missing ? 'Missing ' . count($missing) . ' ids (re-run to retry): ' . implode(', ', $missing) . "\n" : "No missing ids.\n";
