<?php

namespace App\Tests\Support;

use Firebase\JWT\JWT;

/** Własne klucze RSA i tokeny testowe: test nie potrzebuje prawdziwego huba. */
final class JwtFactory
{
    public const ISSUER = 'https://hub.test';
    public const SUB = '0192f0a4-5c1e-7a2b-9d3c-4e5f6a7b8c9d';

    /** @var array<string, \OpenSSLAsymmetricKey> */
    private static array $keys = [];

    private function __construct() {}

    public static function key(string $kid = 'k1'): \OpenSSLAsymmetricKey
    {
        return self::$keys[$kid] ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA])
            ?: throw new \RuntimeException('Nie udało się wygenerować klucza RSA');
    }

    /** @param list<string> $kids */
    public static function jwks(array $kids = ['k1']): array
    {
        return ['keys' => array_map(function (string $kid) {
            $rsa = openssl_pkey_get_details(self::key($kid))['rsa'];

            return [
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => $kid,
                'n' => self::b64($rsa['n']),
                'e' => self::b64($rsa['e']),
            ];
        }, $kids)];
    }

    /** @param array<string, mixed> $claims */
    public static function sign(array $claims, string $kid = 'k1', array $header = [], string $alg = 'RS256'): string
    {
        openssl_pkey_export(self::key($kid), $pem);

        return JWT::encode($claims, $pem, $alg, $kid, $header);
    }

    /** Token dla API wystawiany przez hub; `$overrides` nadpisuje claimy (null usuwa claim). */
    public static function apiToken(array $overrides = [], array $header = ['typ' => 'at+jwt'], string $kid = 'k1'): string
    {
        return self::sign(self::claims([
            'aud' => 'urn:home-hub:module:magazyn',
            'client_id' => 'home-hub',
            'name' => 'Ala Żółć',
            'role' => 'adult',
            'scope' => 'magazyn:items:read magazyn:items:write magazyn:items:admin',
        ], $overrides, 60), $kid, $header);
    }

    public static function loginAccessToken(array $overrides = [], array $header = ['typ' => 'at+jwt'], string $kid = 'k1'): string
    {
        return self::sign(self::claims([
            'aud' => 'module-magazyn',
            'client_id' => 'module-magazyn',
            'role' => 'adult',
            'scope' => 'magazyn:items:read magazyn:items:write',
        ], $overrides, 600), $kid, $header);
    }

    public static function idToken(string $nonce, array $overrides = [], string $kid = 'k1'): string
    {
        return self::sign(self::claims([
            'aud' => 'module-magazyn',
            'name' => 'Ala Żółć',
            'role' => 'adult',
            'email' => 'ala@example.org',
            'auth_time' => time(),
            'nonce' => $nonce,
        ], $overrides, 600), $kid);
    }

    private static function claims(array $defaults, array $overrides, int $lifetime): array
    {
        $now = time();
        $claims = array_merge([
            'iss' => self::ISSUER,
            'sub' => self::SUB,
            'jti' => bin2hex(random_bytes(8)),
            'iat' => $now,
            'exp' => $now + $lifetime,
        ], $defaults, $overrides);

        return array_filter($claims, fn($value) => $value !== null);
    }

    private static function b64(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
