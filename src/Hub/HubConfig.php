<?php

namespace App\Hub;

final class HubConfig
{
    public function __construct(
        private readonly string $publicUrl,
        private readonly string $issuer,
        private readonly string $internalUrl,
        public readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $eventKey,
    ) {}

    public function isConfigured(): bool
    {
        return $this->issuer !== '' && $this->clientId !== '' && $this->clientSecret !== '' && $this->publicUrl !== '';
    }

    public function issuer(): string
    {
        return rtrim($this->issuer, '/');
    }

    public function clientSecret(): string
    {
        return $this->clientSecret;
    }

    public function eventKey(): string
    {
        return $this->eventKey;
    }

    public function redirectUri(): string
    {
        return rtrim($this->publicUrl, '/') . '/callback';
    }

    /** Adres huba dla wywołań serwer–serwer. */
    public function serverUrl(string $path): string
    {
        return rtrim($this->internalUrl !== '' ? $this->internalUrl : $this->issuer, '/') . $path;
    }

    public function browserUrl(string $path): string
    {
        return $this->issuer() . $path;
    }
}
