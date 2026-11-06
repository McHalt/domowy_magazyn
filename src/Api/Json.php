<?php

namespace App\Api;

use Symfony\Component\HttpFoundation\Response;

final class Json
{
    private function __construct() {}

    /** @param array<string, string> $headers */
    public static function response(mixed $data, int $status = Response::HTTP_OK, array $headers = []): Response
    {
        return new Response(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json'] + $headers,
        );
    }
}
