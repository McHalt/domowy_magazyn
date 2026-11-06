<?php

namespace App\Api;

use Symfony\Component\HttpFoundation\Request;

/** Parametry `limit` i `cursor` oraz kursorowe dzielenie listy posortowanej po kluczu. */
final class ListQuery
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 200;

    private function __construct(
        public readonly int $limit,
        public readonly ?string $afterKey,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $errors = [];

        $limit = self::DEFAULT_LIMIT;
        if ($request->query->has('limit')) {
            $raw = $request->query->get('limit');
            if (is_string($raw) && preg_match('/^[1-9][0-9]{0,3}$/', $raw) === 1 && (int) $raw <= self::MAX_LIMIT) {
                $limit = (int) $raw;
            } else {
                $errors[] = ['field' => 'limit', 'message' => sprintf('Limit musi być liczbą od 1 do %d', self::MAX_LIMIT)];
            }
        }

        $afterKey = null;
        if ($request->query->has('cursor')) {
            $afterKey = self::decodeCursor($request->query->get('cursor'));
            if ($afterKey === null) {
                $errors[] = ['field' => 'cursor', 'message' => 'Niepoprawny kursor'];
            }
        }

        if ($errors !== []) {
            throw ApiProblem::validation($errors);
        }

        return new self($limit, $afterKey);
    }

    /**
     * @param list<array{key: string, item: mixed}> $rows wiersze posortowane rosnąco po `key` (porównanie bajtowe)
     * @return array{items: list<mixed>, nextCursor: ?string}
     */
    public function page(array $rows): array
    {
        if ($this->afterKey !== null) {
            $rows = array_values(array_filter($rows, fn(array $row) => strcmp($row['key'], $this->afterKey) > 0));
        }

        $pageRows = array_slice($rows, 0, $this->limit);
        $hasMore = count($rows) > $this->limit;

        return [
            'items' => array_map(fn(array $row) => $row['item'], $pageRows),
            'nextCursor' => $hasMore ? self::encodeCursor(end($pageRows)['key']) : null,
        ];
    }

    private static function encodeCursor(string $key): string
    {
        return rtrim(strtr(base64_encode(json_encode(['k' => $key], JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    }

    private static function decodeCursor(mixed $cursor): ?string
    {
        if (!is_string($cursor) || $cursor === '' || strlen($cursor) > 512 || preg_match('/^[A-Za-z0-9_-]+$/', $cursor) !== 1) {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);

        return is_array($data) && is_string($data['k'] ?? null) ? $data['k'] : null;
    }
}
