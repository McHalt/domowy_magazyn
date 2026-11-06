<?php

namespace App\Hub\Jwt;

use App\Hub\HubConfig;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use GuzzleHttp\ClientInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class JwksProvider
{
    private const CACHE_KEY = 'hub_jwks';
    /** Górny limit świeżości: po rotacji awaryjnej (wyciek klucza) hub od razu usuwa stary klucz z JWKS. */
    private const MAX_FRESH_FOR = 300;
    private const KEEP_FOR = 86400;
    private const MIN_RETRY_INTERVAL = 30;

    /** @var array{fetchedAt: int, attemptedAt: int, kidRefreshAt: int, freshFor: int, jwks: array}|null kopia w pamięci na wypadek niezapisywalnego cache */
    private ?array $memo = null;

    public function __construct(
        private readonly HubConfig $config,
        private readonly ClientInterface $http,
        private readonly CacheItemPoolInterface $cache,
        #[Autowire(service: 'monolog.logger.hub')]
        private readonly LoggerInterface $logger,
    ) {}

    /** @return array<string, Key> klucze RS256 po `kid` */
    public function keys(): array
    {
        $state = $this->load();
        if ($state['fetchedAt'] > time() - $state['freshFor'] || $state['attemptedAt'] > time() - self::MIN_RETRY_INTERVAL) {
            return $this->parse($state['jwks']);
        }

        return $this->parse($this->fetchAndStore($state, kidRefresh: false)['jwks']);
    }

    /**
     * Ponowne pobranie przy nieznanym `kid` (rotacja kluczy), nie częściej niż co 30 s, żeby sfałszowane `kid`
     * nie obciążały huba.
     *
     * @return array<string, Key>
     */
    public function refreshForUnknownKid(): array
    {
        $state = $this->load();
        if ($state['kidRefreshAt'] > time() - self::MIN_RETRY_INTERVAL) {
            return $this->parse($state['jwks']);
        }

        return $this->parse($this->fetchAndStore($state, kidRefresh: true)['jwks']);
    }

    /** Przy błędzie pobrania zostają ostatnie znane klucze, a próba jest zapisana, żeby nie ponawiać jej przy każdym żądaniu. */
    private function fetchAndStore(array $state, bool $kidRefresh): array
    {
        $fetched = $this->fetch();
        $jwks = $fetched['jwks'] ?? null;
        $now = time();
        $new = [
            'fetchedAt' => $jwks !== null ? $now : $state['fetchedAt'],
            'freshFor' => $fetched !== null ? $fetched['freshFor'] : $state['freshFor'],
            'attemptedAt' => $now,
            'kidRefreshAt' => $kidRefresh ? $now : $state['kidRefreshAt'],
            'jwks' => $jwks ?? $state['jwks'],
        ];

        $this->memo = $new;
        if (!$this->cache->save($this->cache->getItem(self::CACHE_KEY)->set($new)->expiresAfter(self::KEEP_FOR))) {
            $this->logger->error('Nie udało się zapisać cache JWKS: czy APP_SHARE_DIR jest zapisywalny?');
        }

        return $new;
    }

    /** @return array{fetchedAt: int, attemptedAt: int, kidRefreshAt: int, freshFor: int, jwks: array} */
    private function load(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $stored = $this->cache->getItem(self::CACHE_KEY)->get();

        return is_array($stored) && isset($stored['jwks'])
            ? $stored + ['fetchedAt' => 0, 'attemptedAt' => 0, 'kidRefreshAt' => 0, 'freshFor' => self::MAX_FRESH_FOR]
            : ['fetchedAt' => 0, 'attemptedAt' => 0, 'kidRefreshAt' => 0, 'freshFor' => self::MAX_FRESH_FOR, 'jwks' => ['keys' => []]];
    }

    /** @return array{jwks: array, freshFor: int}|null */
    private function fetch(): ?array
    {
        try {
            $response = $this->http->request('GET', $this->config->serverUrl('/.well-known/jwks.json'));
            $data = json_decode((string) $response->getBody(), true);
            if ($response->getStatusCode() === 200 && is_array($data) && is_array($data['keys'] ?? null)) {
                return ['jwks' => $data, 'freshFor' => self::freshFor($response->getHeaderLine('Cache-Control'))];
            }
            $this->logger->error('Pobranie JWKS nie powiodło się', ['status' => $response->getStatusCode()]);
        } catch (\Throwable $e) {
            $this->logger->error('Pobranie JWKS nie powiodło się', ['exception' => $e]);
        }

        return null;
    }

    /** `max-age` odpowiedzi huba, ale nie więcej niż 5 min i nie mniej niż odstęp między próbami (30 s). */
    private static function freshFor(string $cacheControl): int
    {
        $maxAge = preg_match('/(?:^|[\s,])max-age=(\d+)/i', $cacheControl, $m) === 1 ? (int) $m[1] : self::MAX_FRESH_FOR;

        return max(self::MIN_RETRY_INTERVAL, min($maxAge, self::MAX_FRESH_FOR));
    }

    /** @return array<string, Key> */
    private function parse(array $jwks): array
    {
        $usable = array_values(array_filter(
            $jwks['keys'] ?? [],
            fn($key) => is_array($key)
                && ($key['kty'] ?? null) === 'RSA'
                && is_string($key['kid'] ?? null) && $key['kid'] !== ''
                && (($key['use'] ?? 'sig') === 'sig'),
        ));

        $keys = [];
        foreach ($usable as $jwk) {
            try {
                $keys += JWK::parseKeySet(['keys' => [$jwk]], 'RS256');
            } catch (\Throwable $e) {
                $this->logger->error('Niepoprawny klucz w JWKS', ['kid' => $jwk['kid'], 'exception' => $e]);
            }
        }

        return $keys;
    }
}
