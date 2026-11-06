<?php

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Aplikacja z atrapą huba (kolejka odpowiedzi Guzzle) i czystą bazą testową. */
abstract class HubTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected MockHandler $hub;
    /** @var list<array{request: \Psr\Http\Message\RequestInterface, response: ?\Psr\Http\Message\ResponseInterface}> */
    protected array $hubCalls = [];
    /** Atrapa sama odpowiada JWKS na /.well-known/jwks.json (Symfony czyści cache między żądaniami); wyłączana tam, gdzie test liczy pobrania. */
    protected bool $autoJwks = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->hub = new MockHandler();
        $stack = HandlerStack::create(function (RequestInterface $request, array $options) {
            if ($this->autoJwks && $request->getUri()->getPath() === '/.well-known/jwks.json') {
                return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode(JwtFactory::jwks())));
            }

            return ($this->hub)($request, $options);
        });
        $stack->push(Middleware::history($this->hubCalls));
        static::getContainer()->set('hub.http_client', new Client(['handler' => $stack, 'http_errors' => false, 'allow_redirects' => false]));

        $this->db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->db()->isTransactionActive()) {
            $this->db()->rollBack();
        }
        parent::tearDown();
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function db(): Connection
    {
        return $this->em()->getConnection();
    }

    protected function queueJwks(array $kids = ['k1']): void
    {
        $this->hub->append(new Response(200, ['Content-Type' => 'application/json'], json_encode(JwtFactory::jwks($kids))));
    }

    protected function queueJson(int $status, array $body, array $headers = []): void
    {
        $this->hub->append(new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body)));
    }

    /**
     * Wywołanie API z tokenem huba; `$token` null = domyślny token ze wszystkimi scope-ami, false = bez nagłówka.
     *
     * @return array<string, mixed>
     */
    protected function api(string $method, string $path, ?array $json = null, string|false|null $token = null, array $server = []): array
    {
        if ($token !== false) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . ($token ?? JwtFactory::apiToken());
        }
        if ($json !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }

        $this->client->request($method, $path, [], [], $server, $json === null ? null : json_encode($json));

        $body = $this->client->getResponse()->getContent();

        return $body === '' ? [] : (json_decode($body, true) ?? ['_raw' => $body]);
    }

    protected function responseStatus(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

}
