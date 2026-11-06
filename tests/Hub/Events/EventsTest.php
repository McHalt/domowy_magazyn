<?php

namespace App\Tests\Hub\Events;

use App\Hub\Events\EventFactory;
use App\Hub\Events\HmacSigner;
use App\Tests\Support\HubTestCase;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class EventsTest extends HubTestCase
{
    private const EVENT_KEY = 'hJ0tY7qk2N3vXw9Lc4bRz8sPmA1eGf6uDi5oKj0nTlU';

    private function run_(string $command): CommandTester
    {
        $application = new Application(static::$kernel);
        $tester = new CommandTester($application->find($command));
        $tester->execute([]);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        return $tester;
    }

    private function today(int $plusDays = 0): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Warsaw')))->modify(sprintf('%+d days', $plusDays))->format('Y-m-d');
    }

    private function product(string $name, array $extra = []): int
    {
        $body = $this->api('POST', '/api/v1/products', ['ean' => (string) random_int(10_000, 99_999_999), 'name' => $name] + $extra);
        $this->assertSame(201, $this->responseStatus(), json_encode($body));

        return $body['id'];
    }

    private function outbox(): array
    {
        return $this->db()->fetchAllAssociative('SELECT * FROM hub_outbox ORDER BY id');
    }

    private function enqueue(string $id = 'evt-1', ?\DateTimeImmutable $createdAt = null): void
    {
        $event = (new EventFactory())->stockLow($id, $createdAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')), [
            ['name' => 'Mleko', 'basis' => 'packages', 'current' => 1, 'minimum' => 2, 'unit' => null],
        ]);
        $this->em()->persist($event);
        $this->em()->flush();
    }

    public function testScanQueuesOneExpiringEventForThresholdDaysOnly(): void
    {
        $milk = $this->product('Mleko', ['producer' => 'Łaciate']);
        $this->api('POST', "/api/v1/products/$milk/stock", ['quantity' => 2, 'expirationDate' => $this->today(1)]);
        $this->api('POST', "/api/v1/products/$milk/stock", ['expirationDate' => $this->today(5)]);
        $rice = $this->product('Ryż');
        $this->api('POST', "/api/v1/products/$rice/stock", ['expirationDate' => $this->today(3)]);
        $this->api('POST', "/api/v1/products/$rice/stock", ['expirationDate' => $this->today(7)]);
        $this->api('POST', "/api/v1/products/$rice/stock", ['expirationDate' => $this->today(-1)]);

        $this->run_('app:hub:scan');
        $this->run_('app:hub:scan');

        $rows = $this->outbox();
        $this->assertCount(1, $rows);
        $this->assertSame('expiring-' . str_replace('-', '', $this->today()), $rows[0]['id']);
        $this->assertSame('pending', $rows[0]['status']);

        $event = json_decode($rows[0]['body'], true);
        $this->assertSame('magazyn.expiring', $event['type']);
        $this->assertArrayNotHasKey('actorUserId', $event);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $event['occurredAt']);
        $this->assertSame(4, $event['payload']['count']);
        $this->assertSame("Jutro: Łaciate Mleko ×2\nZa 3 dni: Ryż\nZa 7 dni: Ryż", $event['payload']['message']);
        $this->assertStringContainsString('Łaciate', $rows[0]['body'], 'UTF-8 bez ucieczek');
    }

    public function testScanWithNothingExpiringQueuesNothing(): void
    {
        $milk = $this->product('Mleko');
        $this->api('POST', "/api/v1/products/$milk/stock", ['expirationDate' => $this->today(30)]);

        $this->run_('app:hub:scan');

        $this->assertSame([], $this->outbox());
    }

    public function testStockLowEventOnlyForNewCrossings(): void
    {
        $milk = $this->product('Mleko');
        $path = "/api/v1/products/$milk";
        $this->api('PUT', "$path/min-stock", ['packages' => 2, 'amount' => null]);
        $this->api('POST', "$path/stock");

        $this->run_('app:hub:scan');
        $rows = $this->outbox();
        $this->assertCount(1, $rows);
        $this->assertMatchesRegularExpression('/^stock_low-\d{8}T\d{6}Z-[0-9a-f]{6}$/', $rows[0]['id']);
        $event = json_decode($rows[0]['body'], true);
        $this->assertSame('magazyn.stock_low', $event['type']);
        $this->assertSame('Mleko: 1 op. z 2 op.', $event['payload']['message']);
        $this->assertSame(1, $event['payload']['count']);
        $this->assertSame(["p:$milk:packages"], $this->db()->fetchFirstColumn('SELECT alert_key FROM hub_alert_state'));

        $this->run_('app:hub:scan');
        $this->assertCount(1, $this->outbox(), 'ten sam próg nie jest zgłaszany ponownie');

        $this->api('POST', "$path/stock");
        $this->run_('app:hub:scan');
        $this->assertCount(1, $this->outbox());
        $this->assertSame([], $this->db()->fetchFirstColumn('SELECT alert_key FROM hub_alert_state'), 'powrót powyżej minimum czyści stan');

        $this->api('POST', "$path/consume", ['quantity' => 2]);
        $this->run_('app:hub:scan');
        $this->assertCount(2, $this->outbox(), 'nowe przekroczenie jest zgłaszane');
    }

    public function testSendSignsExactStoredBodyAndMarksSent(): void
    {
        $this->enqueue();
        $this->hub->append(new Response(202));

        $this->run_('app:hub:send-events');

        $this->assertCount(1, $this->hubCalls);
        $request = $this->hubCalls[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://hub.test/events', (string) $request->getUri());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('magazyn', $request->getHeaderLine('X-Module-Id'));
        $timestamp = $request->getHeaderLine('X-Timestamp');
        $this->assertMatchesRegularExpression('/^\d{10}$/', $timestamp);
        $this->assertEqualsWithDelta(time(), (int) $timestamp, 5);

        $body = (string) $request->getBody();
        $this->assertSame($this->outbox()[0]['body'], $body);
        $this->assertSame(hash_hmac('sha256', $timestamp . '.' . $body, self::EVENT_KEY), $request->getHeaderLine('X-Signature'));
        $this->assertSame(HmacSigner::sign(self::EVENT_KEY, $timestamp, $body), $request->getHeaderLine('X-Signature'));
        $this->assertSame('sent', $this->outbox()[0]['status']);
    }

    public function testServerErrorIsRetriedWithSameIdAndNewTimestamp(): void
    {
        $this->enqueue('evt-retry');
        $this->hub->append(new Response(503));

        $this->run_('app:hub:send-events');

        $row = $this->outbox()[0];
        $this->assertSame('pending', $row['status']);
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertSame('status 503', $row['last_error']);

        $this->db()->executeStatement('UPDATE hub_outbox SET next_attempt_at = ?', [(new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')]);
        $this->hub->append(new Response(202));
        $this->run_('app:hub:send-events');

        $this->assertCount(2, $this->hubCalls);
        $this->assertSame((string) $this->hubCalls[0]['request']->getBody(), (string) $this->hubCalls[1]['request']->getBody());
        $this->assertSame('sent', $this->outbox()[0]['status']);
        $this->assertSame(2, (int) $this->outbox()[0]['attempts']);
    }

    public function testRetryBackoffGrows(): void
    {
        $this->enqueue();
        $delays = [];
        foreach ([1, 10, 60, 60] as $expected) {
            $this->db()->executeStatement('UPDATE hub_outbox SET next_attempt_at = ?', [(new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')]);
            $this->hub->append(new Response(500));
            $before = time();
            $this->run_('app:hub:send-events');
            $delays[] = (new \DateTimeImmutable($this->outbox()[0]['next_attempt_at'], new \DateTimeZone('UTC')))->getTimestamp() - $before;
        }

        foreach ([1, 10, 60, 60] as $i => $expected) {
            $this->assertEqualsWithDelta($expected, $delays[$i], 3);
        }
    }

    public function testNetworkErrorIsRetried(): void
    {
        $this->enqueue();
        $this->hub->append(new ConnectException('boom', new Request('POST', 'https://hub.test/events')));

        $this->run_('app:hub:send-events');

        $row = $this->outbox()[0];
        $this->assertSame('pending', $row['status']);
        $this->assertSame('błąd sieci', $row['last_error']);
    }

    public function testTooManyRequestsHonoursRetryAfter(): void
    {
        $this->enqueue();
        $this->hub->append(new Response(429, ['Retry-After' => '120']));
        $before = time();

        $this->run_('app:hub:send-events');

        $next = (new \DateTimeImmutable($this->outbox()[0]['next_attempt_at'], new \DateTimeZone('UTC')))->getTimestamp();
        $this->assertGreaterThanOrEqual($before + 119, $next);
        $this->assertSame('pending', $this->outbox()[0]['status']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('permanentErrors')]
    public function testClientErrorsAreNotRetried(int $status): void
    {
        $this->enqueue();
        $this->queueJson($status, ['code' => 'validation_failed']);

        $this->run_('app:hub:send-events');
        $this->run_('app:hub:send-events');

        $this->assertCount(1, $this->hubCalls);
        $row = $this->outbox()[0];
        $this->assertSame('failed', $row['status']);
        $this->assertSame("status $status validation_failed", $row['last_error']);
    }

    public static function permanentErrors(): iterable
    {
        yield [400];
        yield [401];
        yield [403];
        yield [413];
        yield [422];
    }

    public function testEventIsDroppedAfterOneHourOfRetries(): void
    {
        $this->enqueue('evt-old', new \DateTimeImmutable('-2 hours', new \DateTimeZone('UTC')));
        $this->hub->append(new Response(500));

        $this->run_('app:hub:send-events');

        $this->assertSame('failed', $this->outbox()[0]['status']);
        $this->assertStringContainsString('minął czas ponawiania', $this->outbox()[0]['last_error']);
    }

    public function testEventNotYetDueIsSkipped(): void
    {
        $this->enqueue();
        $this->db()->executeStatement('UPDATE hub_outbox SET next_attempt_at = ?', [(new \DateTimeImmutable('+10 minutes', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')]);

        $this->run_('app:hub:send-events');

        $this->assertCount(0, $this->hubCalls);
    }

    public function testFinishedEventsAreCleanedUpAfterRetention(): void
    {
        $this->enqueue('evt-done');
        $this->db()->executeStatement("UPDATE hub_outbox SET status = 'sent', created_at = ?", [(new \DateTimeImmutable('-40 days', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')]);

        $this->run_('app:hub:send-events');

        $this->assertSame([], $this->outbox());
    }

    public function testMessageIsTruncatedTo500CharactersAndPayloadFitsLimit(): void
    {
        $items = array_map(fn(int $i) => [
            'name' => 'Bardzo długa nazwa produktu numer ' . $i, 'producer' => 'Producent', 'ean' => (string) $i, 'daysLeft' => 3, 'quantity' => 1,
        ], range(1, 60));

        $event = json_decode((new EventFactory())->expiring('evt-big', new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $items)->getBody(), true);

        $this->assertLessThanOrEqual(500, mb_strlen($event['payload']['message']));
        $this->assertMatchesRegularExpression('/… i \d+ więcej$/u', $event['payload']['message']);
        $this->assertLessThanOrEqual(2048, strlen(json_encode($event['payload'], JSON_UNESCAPED_UNICODE)));
        $this->assertSame(60, $event['payload']['count']);
    }
}
