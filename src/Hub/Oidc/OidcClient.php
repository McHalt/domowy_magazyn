<?php

namespace App\Hub\Oidc;

use App\Hub\HubConfig;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

final class OidcClient
{
    public function __construct(
        private readonly HubConfig $config,
        private readonly ClientInterface $http,
    ) {}

    public function authorizationUrl(string $state, string $nonce, string $codeChallenge): string
    {
        return $this->config->browserUrl('/oauth/authorize') . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->config->clientId,
            'redirect_uri' => $this->config->redirectUri(),
            'scope' => 'openid profile email',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array<string, mixed> */
    public function exchangeCode(string $code, string $codeVerifier): array
    {
        return $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->config->redirectUri(),
            'code_verifier' => $codeVerifier,
        ]);
    }

    /** @return array<string, mixed> */
    public function refresh(string $refreshToken): array
    {
        return $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    /** Błędy odwołania nie przerywają wylogowania; wywołujący je loguje. */
    public function revoke(string $refreshToken): void
    {
        $response = $this->send('/oauth/revoke', ['token' => $refreshToken, 'token_type_hint' => 'refresh_token']);
        if ($response->getStatusCode() >= 400) {
            throw new OidcException('revoke_failed', 'Odwołanie tokenu: status ' . $response->getStatusCode());
        }
    }

    /** @return array<string, mixed> */
    private function tokenRequest(array $form): array
    {
        $response = $this->send('/oauth/token', $form);
        $data = json_decode((string) $response->getBody(), true);
        $status = $response->getStatusCode();

        if ($status >= 500) {
            throw new HubUnavailableException('Hub odpowiedział statusem ' . $status);
        }
        if ($status !== 200 || !is_array($data) || !is_string($data['access_token'] ?? null)) {
            $error = is_array($data) && is_string($data['error'] ?? null) ? $data['error'] : 'invalid_response';
            throw new OidcException($error, 'Odpowiedź /oauth/token: status ' . $status . ', error ' . $error);
        }

        return $data;
    }

    private function send(string $path, array $form): \Psr\Http\Message\ResponseInterface
    {
        try {
            return $this->http->request('POST', $this->config->serverUrl($path), [
                'headers' => ['Authorization' => $this->basicAuth()],
                'form_params' => $form,
            ]);
        } catch (GuzzleException $e) {
            throw new HubUnavailableException('Hub niedostępny: ' . get_class($e), previous: $e);
        }
    }

    private function basicAuth(): string
    {
        return 'Basic ' . base64_encode(urlencode($this->config->clientId) . ':' . urlencode($this->config->clientSecret()));
    }
}
