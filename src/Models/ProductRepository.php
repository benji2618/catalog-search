<?php
declare(strict_types=1);
namespace App\Models;

use App\Database;
use PDO;

/** All SQL lives here. */
final class ProductRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::get();
    }

    /**
     * Ids ranked by 2 * name-match + search_text-match. Each term is a quoted phrase, phrases are OR-ed.
     * (BOOLEAN MODE: NATURAL LANGUAGE mode ORs raw ngram bigrams and matches almost anything.)
     * Terms must already be stripped of boolean operator characters.
     * @param string[] $terms @return string[]
     */
    public function lexicalSearch(array $terms, int $limit = 50): array
    {
        if (!$terms) return [];
        $q = implode(' ', array_map(fn($t) => '"' . $t . '"', $terms));
        $stmt = $this->pdo->prepare(
            'SELECT id, 2 * MATCH(name, name_en, brand) AGAINST(:q1 IN BOOLEAN MODE)
                         + MATCH(search_text) AGAINST(:q2 IN BOOLEAN MODE) AS score
             FROM products HAVING score > 0 ORDER BY score DESC, id LIMIT ' . max(1, $limit)
        );
        $stmt->execute(['q1' => $q, 'q2' => $q]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Ids whose search_text contains every term (+"t1" +"t2"). search_text already holds name, name_en and brand.
     * @param string[] $terms @return string[]
     */
    public function allTermsSearch(array $terms): array
    {
        if (!$terms) return [];
        $q = implode(' ', array_map(fn($t) => '+"' . $t . '"', $terms));
        $stmt = $this->pdo->prepare('SELECT id FROM products WHERE MATCH(search_text) AGAINST(? IN BOOLEAN MODE)');
        $stmt->execute([$q]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @param string[] $ids @return array[] products in the order of $ids */
    public function findByIds(array $ids): array
    {
        if (!$ids) return [];
        $stmt = $this->pdo->prepare(
            'SELECT id, name, name_en, brand, category, price, image_url FROM products WHERE id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute(array_values($ids));
        $byId = array_column($stmt->fetchAll(), null, 'id');
        return array_values(array_filter(array_map(fn($id) => $byId[$id] ?? null, $ids)));
    }

    /** @return array<string, string> id => packed float32 BLOB */
    public function allEmbeddings(): array
    {
        return $this->pdo->query('SELECT id, embedding FROM products WHERE embedding IS NOT NULL')
            ->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function getCachedQueryEmbedding(string $hash): ?string
    {
        $stmt = $this->pdo->prepare('SELECT embedding FROM query_cache WHERE query_hash = ?');
        $stmt->execute([$hash]);
        $blob = $stmt->fetchColumn();
        return $blob === false ? null : $blob;
    }

    public function saveQueryEmbedding(string $hash, string $text, string $blob): void
    {
        $this->pdo->prepare('INSERT IGNORE INTO query_cache (query_hash, query_text, embedding) VALUES (?, ?, ?)')
            ->execute([$hash, $text, $blob]);
    }
}
