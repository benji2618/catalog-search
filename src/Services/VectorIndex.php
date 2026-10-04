<?php
declare(strict_types=1);
namespace App\Services;

use App\Config;
use App\Models\ProductRepository;
use RuntimeException;

/**
 * Brute-force dot-product search over all product vectors (unit length, so dot product = cosine).
 * The packed BLOBs are cached in APCu when available (else read from MySQL per request).
 * After re-ingesting, bump VECTOR_INDEX_VERSION in .env (and reload PHP-FPM) to invalidate the cache.
 */
final class VectorIndex
{
    private const CACHE_TTL = 3600; // a stale index self-heals even if the version isn't bumped

    /** @var array<string, string>|null id => packed vector */
    private ?array $blobs = null;

    public function __construct(private ProductRepository $repo = new ProductRepository()) {}

    /** @param float[] $queryVector @return array<string, float> id => score, best first, only scores >= $minScore */
    public function topK(array $queryVector, int $k = 50, float $minScore = 0.0): array
    {
        // unpack('g*') returns keys 1..n, so shift the query by one slot to index both the same way
        array_unshift($queryVector, 0.0);
        $scores = [];
        foreach ($this->blobs() as $id => $blob) {
            $v = unpack('g*', $blob);
            if (count($v) !== count($queryVector) - 1) throw new RuntimeException("Vector size mismatch for $id");
            $dot = 0.0;
            foreach ($v as $i => $x) $dot += $x * $queryVector[$i];
            if ($dot >= $minScore) $scores[$id] = $dot;
        }
        arsort($scores);
        return array_slice($scores, 0, $k, true);
    }

    private function blobs(): array
    {
        if ($this->blobs !== null) return $this->blobs;
        $apcu = function_exists('apcu_enabled') && apcu_enabled();
        $key = 'vector_index_v' . Config::get('VECTOR_INDEX_VERSION', '1');
        if ($apcu) {
            $cached = apcu_fetch($key, $hit);
            if ($hit) return $this->blobs = $cached;
        }
        $this->blobs = $this->repo->allEmbeddings();
        if ($apcu) apcu_store($key, $this->blobs, self::CACHE_TTL);
        return $this->blobs;
    }
}
