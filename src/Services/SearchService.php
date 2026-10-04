<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\ProductRepository;
use JsonException;
use RuntimeException;

final class SearchService
{
    private const POOL_SIZE = 50;           // candidates taken from each of lexical and semantic
    private const RESULT_SIZE = 24;
    private const RRF_K = 60;
    // A semantic candidate must score >= max(MIN_SCORE, bestScore - BAND): score scales vary a lot per query
    private const SEMANTIC_MIN_SCORE = 0.30;
    private const SEMANTIC_BAND = 0.12;
    private const STOPWORDS = ['to', 'on', 'my', 'a', 'an', 'the', 'for', 'of', 'with', 'in', 'and',
        'את', 'של', 'עם', 'על', 'גם', 'או'];

    public function __construct(
        private ProductRepository $repo = new ProductRepository(),
        private EmbeddingClient $embedder = new EmbeddingClient(),
        private VectorIndex $index = new VectorIndex(),
    ) {}

    /**
     * @return array{results: array[], semantic_ok: bool, debug: array<string, array>}
     *   debug: id => tier (1 = matches all terms, 2 = other), lexical_rank, semantic_rank, semantic_score
     */
    public function search(string $q): array
    {
        $q = mb_substr(trim(preg_replace('/\s+/u', ' ', $q)), 0, 100);
        if ($q === '') return ['results' => [], 'semantic_ok' => true, 'debug' => []];

        // Lexical terms only: operators and stopwords removed (the embedding sees the full query)
        $clean = preg_replace('/[+\-<>()~*"@]+/u', ' ', $q);
        $terms = array_values(array_filter(explode(' ', $clean),
            fn($t) => mb_strlen($t) >= 2 && !in_array(mb_strtolower($t), self::STOPWORDS, true)));
        $lexical = $this->repo->lexicalSearch($terms, self::POOL_SIZE);
        $tier1 = array_flip($this->repo->allTermsSearch($terms));

        $semantic = [];
        $semanticOk = true;
        try {
            $semantic = $this->index->topK($this->queryVector($q), self::POOL_SIZE, self::SEMANTIC_MIN_SCORE);
            $cut = ($semantic ? reset($semantic) : 0.0) - self::SEMANTIC_BAND;
            $semantic = array_filter($semantic, fn($s) => $s >= $cut);
        } catch (RuntimeException | JsonException) {
            $semanticOk = false; // Voyage (or cache) failed: lexical results only
        }

        // Reciprocal Rank Fusion, then tier 1 (all terms matched) before the rest
        $fused = [];
        $debug = [];
        foreach ($lexical as $i => $id) {
            $fused[$id] = ($fused[$id] ?? 0) + 1 / (self::RRF_K + $i + 1);
            $debug[$id]['lexical_rank'] = $i + 1;
        }
        $rank = 0;
        foreach ($semantic as $id => $score) {
            $fused[$id] = ($fused[$id] ?? 0) + 1 / (self::RRF_K + ++$rank);
            $debug[$id]['semantic_rank'] = $rank;
            $debug[$id]['semantic_score'] = $score;
        }
        // Lexical-only hits matching just some terms are mostly noise: keep them only if the semantic step is down
        if ($semanticOk) {
            $fused = array_filter($fused, fn($id) => isset($tier1[$id]) || isset($debug[$id]['semantic_rank']), ARRAY_FILTER_USE_KEY);
        }
        uksort($fused, fn($a, $b) => [isset($tier1[$b]), $fused[$b]] <=> [isset($tier1[$a]), $fused[$a]]);
        foreach ($fused as $id => $_) $debug[$id]['tier'] = isset($tier1[$id]) ? 1 : 2;

        return [
            'results' => $this->repo->findByIds(array_slice(array_keys($fused), 0, self::RESULT_SIZE)),
            'semantic_ok' => $semanticOk,
            'debug' => $debug,
        ];
    }

    /** @return float[] */
    private function queryVector(string $q): array
    {
        $q = mb_strtolower($q); // the exact text that is hashed, embedded and stored
        $hash = hash('sha256', $q);
        $blob = $this->repo->getCachedQueryEmbedding($hash);
        if ($blob !== null) return EmbeddingClient::unpack($blob);

        $vector = $this->embedder->embedQuery($q);
        $this->repo->saveQueryEmbedding($hash, $q, EmbeddingClient::pack($vector));
        return $vector;
    }
}
