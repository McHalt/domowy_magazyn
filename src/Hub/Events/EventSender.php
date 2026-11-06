<?php

namespace App\Hub\Events;

use App\Entity\OutboxEvent;
use App\Hub\HubConfig;
use App\Repository\OutboxEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Wysyła zaległe zdarzenia z kolejki: `POST <hub>/events` z podpisem HMAC, ponawianie przez najwyżej godzinę. */
final class EventSender
{
    private const RETRY_DELAYS = [1, 10, 60];
    private const RETRY_WINDOW = 3600;
    private const MAX_RETRY_AFTER = 300;

    public function __construct(
        private readonly HubConfig $config,
        private readonly ClientInterface $http,
        private readonly OutboxEventRepository $outbox,
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'monolog.logger.hub')]
        private readonly LoggerInterface $logger,
    ) {}

    /** @return array{sent: int, retry: int, failed: int} */
    public function deliverDue(\DateTimeImmutable $now): array
    {
        $result = ['sent' => 0, 'retry' => 0, 'failed' => 0];
        if ($this->config->eventKey() === '') {
            $this->logger->warning('HUB_EVENT_KEY nie jest ustawiony: zdarzenia czekają w kolejce');

            return $result;
        }

        foreach ($this->outbox->findDue($now) as $event) {
            $result[$this->deliver($event, $now)]++;
            $this->em->flush();
        }

        return $result;
    }

    /** @return 'sent'|'retry'|'failed' */
    private function deliver(OutboxEvent $event, \DateTimeImmutable $now): string
    {
        $status = null;
        $retryAfter = null;
        $problemCode = null;

        try {
            $timestamp = (string) time();
            $response = $this->http->request('POST', $this->config->serverUrl('/events'), [
                'body' => $event->getBody(),
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Module-Id' => 'magazyn',
                    'X-Timestamp' => $timestamp,
                    'X-Signature' => HmacSigner::sign($this->config->eventKey(), $timestamp, $event->getBody()),
                ],
            ]);
            $status = $response->getStatusCode();
            $retryAfter = ctype_digit($response->getHeaderLine('Retry-After')) ? (int) $response->getHeaderLine('Retry-After') : null;
            $problem = json_decode((string) $response->getBody(), true);
            $problemCode = is_array($problem) && is_string($problem['code'] ?? null) ? $problem['code'] : null;
        } catch (GuzzleException $e) {
            $this->logger->warning('Wysyłka zdarzenia: błąd sieci', ['eventId' => $event->getId(), 'exception' => get_class($e)]);
        }

        if ($status !== null && $status >= 200 && $status < 300) {
            $event->markSent();
            $this->logger->info('Zdarzenie wysłane', ['eventId' => $event->getId(), 'status' => $status]);

            return 'sent';
        }

        $retryable = $status === null || $status === 429 || $status >= 500;
        $error = $status === null ? 'błąd sieci' : sprintf('status %d%s', $status, $problemCode !== null ? ' ' . $problemCode : '');

        if (!$retryable) {
            $event->markFailed($error);
            $this->logger->error('Zdarzenie odrzucone przez hub, bez ponawiania', ['eventId' => $event->getId(), 'status' => $status, 'code' => $problemCode]);

            return 'failed';
        }

        // createdAt jest zapisany jako UTC, ale odczytany w strefie PHP, więc wiek liczymy z napisu.
        $createdUtc = new \DateTimeImmutable($event->getCreatedAt()->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
        if ($now->getTimestamp() - $createdUtc->getTimestamp() >= self::RETRY_WINDOW) {
            $event->markFailed($error . ', minął czas ponawiania');
            $this->logger->error('Zdarzenie porzucone po godzinie ponawiania', ['eventId' => $event->getId(), 'last' => $error]);

            return 'failed';
        }

        $delay = self::RETRY_DELAYS[$event->getAttempts()] ?? 60;
        if ($status === 429 && $retryAfter !== null) {
            $delay = max($delay, min($retryAfter, self::MAX_RETRY_AFTER));
        }
        $event->scheduleRetry($now->modify(sprintf('+%d seconds', $delay)), $error);

        return 'retry';
    }
}
