<?php
declare(strict_types=1);
namespace App\Services;

use App\Config;
use RuntimeException;

final class EmbeddingClient
{
    private const URL = 'https://api.voyageai.com/v1/embeddings';
    private const BATCH_SIZE = 100;

    /** @param string[] $texts @return float[][] unit-length vectors, same order as $texts */
    public function embedDocuments(array $texts): array
    {
        $vectors = [];
        foreach (array_chunk(array_values($texts), self::BATCH_SIZE) as $batch) {
            $data = $this->request($batch, 'document');
            if (count($data) !== count($batch)) {
                throw new RuntimeException(sprintf('Voyage returned %d vectors for %d texts', count($data), count($batch)));
            }
            usort($data, fn($a, $b) => $a['index'] <=> $b['index']);
            foreach ($data as $item) $vectors[] = self::normalize($item['embedding']);
        }
        return $vectors;
    }

    /** @return float[] one unit-length vector */
    public function embedQuery(string $text): array
    {
        $data = $this->request([$text], 'query');
        if (count($data) !== 1) throw new RuntimeException('Voyage returned ' . count($data) . ' vectors for 1 query');
        return self::normalize($data[0]['embedding']);
    }

    /** @return array[] the response's "data" items (index + embedding). Retries once on 429 / 5xx / curl errors; any other failure throws immediately. */
    private function request(array $input, string $inputType): array
    {
        $payload = json_encode(['input' => $input, 'model' => Config::get('VOYAGE_MODEL'), 'input_type' => $inputType], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        for ($try = 1; ; $try++) {
            $ch = curl_init(self::URL);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . Config::get('VOYAGE_API_KEY'), 'Content-Type: application/json'],
                CURLOPT_POSTFIELDS => $payload,
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = $body === false ? 'curl: ' . curl_error($ch) : null;

            if ($error === null && $code === 200) break;
            $transient = $error !== null || $code === 429 || $code >= 500;
            $message = $error ?? "Voyage HTTP $code: " . substr((string)$body, 0, 300);
            if (!$transient || $try >= 2) throw new RuntimeException($message);
            sleep(2);
        }

        $data = json_decode($body, true)['data'] ?? null;
        return is_array($data) ? $data : throw new RuntimeException('Voyage: response has no "data" array');
    }

    /** @param float[] $v */
    public static function normalize(array $v): array
    {
        $norm = sqrt(array_sum(array_map(fn($x) => $x * $x, $v)));
        return $norm > 0 ? array_map(fn($x) => $x / $norm, $v) : $v;
    }

    /** @param float[] $v float32 little-endian binary, for the BLOB column */
    public static function pack(array $v): string
    {
        return pack('g*', ...$v);
    }

    /** @return float[] */
    public static function unpack(string $blob): array
    {
        return array_values(unpack('g*', $blob));
    }
}
