<?php

namespace App\Hub\Jwt;

use App\Hub\HubConfig;
use Firebase\JWT\JWT;

final class TokenValidator
{
    public const API_AUDIENCE = 'urn:home-hub:module:magazyn';
    private const MAX_LENGTH = 8192;
    private const LEEWAY = 30;
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private readonly HubConfig $config,
        private readonly JwksProvider $jwks,
    ) {}

    /** Token wystawiony przez hub dla API (aud = urn:home-hub:module:magazyn). */
    public function validateApiToken(string $jwt): VerifiedToken
    {
        return $this->verify($jwt, self::API_AUDIENCE, accessToken: true);
    }

    /** Access token z logowania (aud = client_id). */
    public function validateLoginAccessToken(string $jwt): VerifiedToken
    {
        return $this->verify($jwt, $this->config->clientId, accessToken: true);
    }

    public function validateIdToken(string $jwt, string $nonce): VerifiedToken
    {
        $token = $this->verify($jwt, $this->config->clientId, accessToken: false);
        $tokenNonce = $token->claims['nonce'] ?? null;
        if (!is_string($tokenNonce) || $nonce === '' || !hash_equals($nonce, $tokenNonce)) {
            throw new InvalidTokenException('nonce niezgodny albo brak');
        }

        return $token;
    }

    private function verify(string $jwt, string $audience, bool $accessToken): VerifiedToken
    {
        if (strlen($jwt) > self::MAX_LENGTH || substr_count($jwt, '.') !== 2) {
            throw new InvalidTokenException('niepoprawna postać tokenu');
        }

        $header = $this->header($jwt);
        if (($header['alg'] ?? null) !== 'RS256') {
            throw new InvalidTokenException('alg inny niż RS256');
        }
        if ($accessToken && (!is_string($header['typ'] ?? null) || strtolower($header['typ']) !== 'at+jwt')) {
            throw new InvalidTokenException('typ inny niż at+jwt');
        }
        $kid = $header['kid'] ?? null;
        if (!is_string($kid) || $kid === '') {
            throw new InvalidTokenException('brak kid');
        }

        $keys = $this->jwks->keys();
        if (!isset($keys[$kid])) {
            $keys = $this->jwks->refreshForUnknownKid();
        }
        if (!isset($keys[$kid])) {
            throw new InvalidTokenException('nieznany kid');
        }

        $previousLeeway = JWT::$leeway;
        JWT::$leeway = self::LEEWAY;
        try {
            $claims = (array) JWT::decode($jwt, $keys);
        } catch (\Throwable $e) {
            throw new InvalidTokenException('podpis lub czas: ' . $e->getMessage(), previous: $e);
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        $this->checkClaims($claims, $audience);

        return new VerifiedToken($claims);
    }

    private function header(string $jwt): array
    {
        $header = json_decode(JWT::urlsafeB64Decode(explode('.', $jwt)[0]), true);
        if (!is_array($header)) {
            throw new InvalidTokenException('nagłówek nie jest obiektem JSON');
        }

        return $header;
    }

    private function checkClaims(array $claims, string $audience): void
    {
        if (($claims['iss'] ?? null) !== $this->config->issuer()) {
            throw new InvalidTokenException('iss inny niż HUB_ISSUER');
        }

        $aud = $claims['aud'] ?? null;
        if (!(is_string($aud) ? $aud === $audience : is_array($aud) && in_array($audience, $aud, true))) {
            throw new InvalidTokenException('aud inny niż oczekiwany');
        }

        if (!is_int($claims['exp'] ?? null)) {
            throw new InvalidTokenException('brak exp');
        }

        $sub = $claims['sub'] ?? null;
        if (!is_string($sub) || preg_match(self::UUID, $sub) !== 1) {
            throw new InvalidTokenException('sub nie jest UUID');
        }

        if (isset($claims['scope']) && !is_string($claims['scope'])) {
            throw new InvalidTokenException('scope nie jest ciągiem');
        }
    }
}
