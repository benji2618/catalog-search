<?php
declare(strict_types=1);

// Debug CLI for calibrating search: php scripts/search.php "blender"

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\SearchService;

$query = $argv[1] ?? null;
if ($query === null) {
    fwrite(STDERR, "Usage: php scripts/search.php \"query\"\n");
    exit(1);
}

$t = hrtime(true);
$out = (new SearchService())->search($query);
$ms = (hrtime(true) - $t) / 1e6;

if (!$out['semantic_ok']) echo "!! Semantic search failed: lexical results only\n";
printf("%-4s %-7s %-40s %-5s %-5s %-5s %s\n", '#', 'id', 'name_en', 'tier', 'lex', 'sem', 'score');
foreach ($out['results'] as $i => $p) {
    $d = $out['debug'][$p['id']];
    printf("%-4d %-7s %-40s %-5s %-5s %-5s %s\n", $i + 1, $p['id'], mb_substr((string)$p['name_en'], 0, 40),
        $d['tier'], $d['lexical_rank'] ?? '-', $d['semantic_rank'] ?? '-', isset($d['semantic_score']) ? number_format($d['semantic_score'], 4) : '-');
}
printf("\n%d results in %.0f ms\n", count($out['results']), $ms);
