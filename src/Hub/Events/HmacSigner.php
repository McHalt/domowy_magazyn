<?php

namespace App\Hub\Events;

final class HmacSigner
{
    private function __construct() {}

    /** HMAC-SHA256 (hex) z `<timestamp>.<surowa treść>`; kluczem jest tekst klucza zdarzeń, bez dekodowania base64. */
    public static function sign(string $eventKey, string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $eventKey);
    }
}
