<?php
declare(strict_types=1);

// Runs data/eval_queries.json through SearchService. Exit code 1 if any query fails (usable as a pre-commit check).
// PASS = an expected id is in the top 5, or zero results when "expect" is empty. Rank = first expected id found.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\SearchService;

const TOP_N = 5;

$queries = json_decode(file_get_contents(dirname(__DIR__) . '/data/eval_queries.json'), true)
    ?: throw new RuntimeException('data/eval_queries.json is missing or invalid');
$search = new SearchService();

$stats = []; // source => [passed, total]
foreach ($queries as $item) {
    $out = $search->search($item['q']);
    $ids = array_column($out['results'], 'id');

    $rank = null;
    foreach ($ids as $i => $id) {
        if (in_array($id, $item['expect'], true)) {
            $rank = $i + 1;
            break;
        }
    }
    $pass = $item['expect'] ? ($rank !== null && $rank <= TOP_N) : count($ids) === 0;

    $source = $item['source'] ?? 'other';
    $stats[$source][0] = ($stats[$source][0] ?? 0) + (int)$pass;
    $stats[$source][1] = ($stats[$source][1] ?? 0) + 1;
    printf("%s  rank %-2s  %-10s  %s%s\n", $pass ? 'PASS' : 'FAIL', $rank ?? '-', $source, $item['q'],
        $out['semantic_ok'] ? '' : '  [lexical only: semantic search failed]');
}

echo "\n";
$passed = $total = 0;
foreach ($stats as $source => [$p, $t]) {
    printf("%-10s %d/%d (%.0f%%)\n", $source, $p, $t, 100 * $p / $t);
    $passed += $p;
    $total += $t;
}
printf("%-10s %d/%d (%.0f%%)\n", 'overall', $passed, $total, 100 * $passed / $total);
exit($passed === $total ? 0 : 1);
