<?php

namespace App\Hub\Events;

use App\Entity\OutboxEvent;

/** Buduje zdarzenia w formacie z docs/04-module-contract.md huba; `body` jest zapisywany raz i wysyłany bez zmian. */
final class EventFactory
{
    public const TYPE_EXPIRING = 'magazyn.expiring';
    public const TYPE_STOCK_LOW = 'magazyn.stock_low';
    private const MAX_MESSAGE = 500;
    private const MAX_PAYLOAD_BYTES = 2048;

    private const DAY_LABELS = [0 => 'Dziś', 1 => 'Jutro', 3 => 'Za 3 dni', 7 => 'Za 7 dni'];

    /**
     * @param list<array{name: ?string, producer: ?string, ean: string, daysLeft: int, quantity: int}> $items
     */
    public function expiring(string $id, \DateTimeImmutable $occurredAt, array $items): OutboxEvent
    {
        $byDay = [];
        foreach ($items as $item) {
            $label = trim(($item['producer'] ?? '') . ' ' . ($item['name'] ?? '')) ?: $item['ean'];
            $byDay[$item['daysLeft']][] = $item['quantity'] > 1 ? sprintf('%s ×%d', $label, $item['quantity']) : $label;
        }
        ksort($byDay);

        $lines = [];
        foreach ($byDay as $days => $labels) {
            $lines[] = (self::DAY_LABELS[$days] ?? sprintf('Za %d dni', $days)) . ': ' . implode(', ', $labels);
        }

        return $this->event($id, self::TYPE_EXPIRING, $occurredAt, 'Kończy się ważność produktów', $lines, array_sum(array_column($items, 'quantity')));
    }

    /**
     * @param list<array{name: ?string, basis: string, current: int|float, minimum: int|float, unit: ?string}> $alerts
     */
    public function stockLow(string $id, \DateTimeImmutable $occurredAt, array $alerts): OutboxEvent
    {
        $lines = array_map(function (array $alert) {
            $name = $alert['name'] ?? 'Bez nazwy';
            $unit = $alert['unit'] !== null ? ' ' . $alert['unit'] : ' op.';

            return sprintf('%s: %s%s z %s%s', $name, self::number($alert['current']), $unit, self::number($alert['minimum']), $unit);
        }, $alerts);

        return $this->event($id, self::TYPE_STOCK_LOW, $occurredAt, 'Produkty poniżej stanu minimalnego', $lines, count($alerts));
    }

    /** @param list<string> $lines */
    private function event(string $id, string $type, \DateTimeImmutable $occurredAt, string $title, array $lines, int $count): OutboxEvent
    {
        $payload = ['title' => $title, 'message' => mb_substr($this->message($lines), 0, self::MAX_MESSAGE), 'count' => $count];
        $body = json_encode([
            'id' => $id,
            'type' => $type,
            'occurredAt' => $occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (strlen(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > self::MAX_PAYLOAD_BYTES) {
            throw new \LogicException('Payload zdarzenia przekracza ' . self::MAX_PAYLOAD_BYTES . ' bajtów.');
        }

        return new OutboxEvent($id, $body, $occurredAt->setTimezone(new \DateTimeZone('UTC')));
    }

    /** Linie do limitu znaków `message`; reszta jako „… i N więcej”. */
    private function message(array $lines): string
    {
        $message = '';
        foreach ($lines as $index => $line) {
            $candidate = $message === '' ? $line : $message . "\n" . $line;
            $rest = count($lines) - $index - 1;
            $suffix = $rest > 0 ? sprintf("\n… i %d więcej", $rest) : '';
            if (mb_strlen($candidate . $suffix) > self::MAX_MESSAGE) {
                $more = count($lines) - $index;
                $tail = sprintf('… i %d więcej', $more);
                if ($message === '') {
                    return mb_substr($line, 0, self::MAX_MESSAGE - mb_strlen($tail) - 1) . $tail;
                }

                return $message . "\n" . $tail;
            }
            $message = $candidate;
        }

        return $message;
    }

    private static function number(int|float $value): string
    {
        return str_replace('.', ',', (string) $value);
    }
}
