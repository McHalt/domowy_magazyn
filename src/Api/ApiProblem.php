<?php

namespace App\Api;

use Symfony\Component\HttpFoundation\Response;

/** Błąd API przekładany na application/problem+json (RFC 9457) ze stałym polem `code`. */
final class ApiProblem extends \RuntimeException
{
    /**
     * @param list<array{field: string, message: string}> $errors
     * @param array<string, mixed> $extra
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $problemCode,
        public readonly string $title,
        public readonly array $errors = [],
        public readonly array $extra = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($title);
    }

    public static function invalidToken(): self
    {
        return new self(Response::HTTP_UNAUTHORIZED, 'invalid_token', 'Niepoprawny token', headers: [
            'WWW-Authenticate' => 'Bearer error="invalid_token"',
        ]);
    }

    public static function insufficientScope(string $requiredScope): self
    {
        return new self(Response::HTTP_FORBIDDEN, 'insufficient_scope', 'Brak uprawnień', extra: ['requiredScope' => $requiredScope]);
    }

    public static function notFound(): self
    {
        return new self(Response::HTTP_NOT_FOUND, 'not_found', 'Nie znaleziono');
    }

    public static function badRequest(): self
    {
        return new self(Response::HTTP_BAD_REQUEST, 'bad_request', 'Niepoprawne żądanie');
    }

    /** @param list<array{field: string, message: string}> $errors */
    public static function validation(array $errors): self
    {
        return new self(Response::HTTP_UNPROCESSABLE_ENTITY, 'validation_failed', 'Niepoprawne dane', errors: $errors);
    }

    public static function eanTaken(int $productId): self
    {
        return new self(Response::HTTP_CONFLICT, 'ean_taken', 'Produkt o tym kodzie EAN już istnieje', extra: ['productId' => $productId]);
    }

    public static function insufficientStock(int $available): self
    {
        return new self(Response::HTTP_CONFLICT, 'insufficient_stock', 'Za mało sztuk na stanie', extra: ['available' => $available]);
    }
}
