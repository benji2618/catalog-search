<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Services\SearchService;
use Throwable;

final class SearchController
{
    private const MAX_QUERY_LENGTH = 100;

    public function handle(): void
    {
        $q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
        if (!mb_check_encoding($q, 'UTF-8')) {
            $this->respond(400, ['error' => 'Query parameter "q" must be valid UTF-8']);
            return;
        }
        if ($q === '' || mb_strlen($q) > self::MAX_QUERY_LENGTH) {
            $this->respond(400, ['error' => 'Query parameter "q" is required (1-' . self::MAX_QUERY_LENGTH . ' characters)']);
            return;
        }

        try {
            $start = hrtime(true);
            $out = (new SearchService())->search($q);
            $this->respond(200, [
                'query' => $q,
                'results' => array_map(fn($p) => [
                    'id' => $p['id'],
                    'name' => $p['name'],
                    'name_en' => $p['name_en'],
                    'brand' => $p['brand'],
                    'category' => $p['category'],
                    'price' => $p['price'] !== null ? (float)$p['price'] : null,
                    'image_url' => $p['image_url'],
                ], $out['results']),
                'semantic' => $out['semantic_ok'],
                'took_ms' => (int)round((hrtime(true) - $start) / 1e6),
            ]);
        } catch (Throwable $e) {
            error_log('Search failed: ' . $e);
            $this->respond(500, ['error' => 'Internal server error']);
        }
    }

    private function respond(int $status, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); // before any output, so a failure can still become a 500
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo $json;
    }
}
