<?php
declare(strict_types=1);

// Loads data/products_enriched.json into MySQL, then embeds rows that have no embedding yet.
// Usage: php scripts/ingest.php [--reembed]   (safe to re-run: only missing embeddings are computed)
// Re-run with --reembed after re-enriching the catalog: the upsert keeps existing embeddings.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Database;
use App\Services\EmbeddingClient;

/** Short fields only: the FULLTEXT ngram index must not see long Hebrew prose. */
function shortFields(array $p): array
{
    return [
        $p['name'], $p['name_en'], $p['brand'], $p['category'], $p['category_en'], $p['subcategory'],
        ...$p['transliterations'], ...$p['uses']['he'], ...$p['uses']['en'],
    ];
}

function joinText(array $parts, string $sep): string
{
    return implode($sep, array_filter(array_map(fn($s) => trim((string)$s), $parts), fn($s) => $s !== ''));
}

$file = dirname(__DIR__) . '/data/products_enriched.json';
if (!is_file($file)) {
    fwrite(STDERR, "Missing $file: run scripts/enrich.php first\n");
    exit(1);
}
$products = json_decode(file_get_contents($file), true) ?: throw new RuntimeException('products_enriched.json is empty or invalid');
$pdo = Database::get();

if (in_array('--reembed', $argv, true)) {
    $pdo->exec('UPDATE products SET embedding = NULL');
    echo "Embeddings cleared\n";
}

// 1. Upsert (never touches the embedding column)
$upsert = $pdo->prepare(
    'INSERT INTO products (id, name, name_en, brand, category, subcategory, description, attributes, image_url, price, search_text)
     VALUES (:id, :name, :name_en, :brand, :category, :subcategory, :description, :attributes, :image_url, :price, :search_text) AS new
     ON DUPLICATE KEY UPDATE name = new.name, name_en = new.name_en, brand = new.brand, category = new.category,
       subcategory = new.subcategory, description = new.description, attributes = new.attributes,
       image_url = new.image_url, price = new.price, search_text = new.search_text'
);
$embeddingTexts = [];
$pdo->beginTransaction();
foreach ($products as $p) {
    $upsert->execute([
        'id' => $p['id'], 'name' => $p['name'], 'name_en' => $p['name_en'], 'brand' => $p['brand'] !== '' ? $p['brand'] : null,
        'category' => $p['category'], 'subcategory' => $p['subcategory'], 'description' => $p['description'],
        'attributes' => $p['attributes'], 'image_url' => $p['image_url'], 'price' => $p['price'],
        'search_text' => joinText(shortFields($p), ' '),
    ]);
    $embeddingTexts[$p['id']] = joinText([...shortFields($p), $p['description'], $p['attributes']], "\n");
}
$pdo->commit();
echo 'Upserted ' . count($products) . " products\n";

// 2. Embed only rows still missing one, saving after every 100
$todo = $pdo->query('SELECT id FROM products WHERE embedding IS NULL')->fetchAll(PDO::FETCH_COLUMN);
$todo = array_values(array_filter($todo, fn($id) => isset($embeddingTexts[$id])));
echo count($todo) . " products to embed\n";

$client = new EmbeddingClient();
$save = $pdo->prepare('UPDATE products SET embedding = :e WHERE id = :id');
foreach (array_chunk($todo, 100) as $n => $ids) {
    $vectors = $client->embedDocuments(array_map(fn($id) => $embeddingTexts[$id], $ids));
    $pdo->beginTransaction();
    foreach ($ids as $i => $id) $save->execute(['e' => EmbeddingClient::pack($vectors[$i]), 'id' => $id]);
    $pdo->commit();
    echo sprintf("Embedded %d/%d\n", min(($n + 1) * 100, count($todo)), count($todo));
}

$s = $pdo->query('SELECT COUNT(*) AS total, COALESCE(SUM(embedding IS NULL), 0) AS missing FROM products')->fetch();
echo "\nRows in table: {$s['total']}\nRows still missing an embedding: {$s['missing']}\n";
