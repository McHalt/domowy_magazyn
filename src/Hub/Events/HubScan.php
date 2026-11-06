<?php

namespace App\Hub\Events;

use App\Entity\AlertState;
use App\Entity\OutboxEvent;
use App\Service\StockMonitor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Skan wykonywany raz dziennie: zapisuje do kolejki co najwyżej po jednym zdarzeniu każdego typu.
 *  - magazyn.expiring: sztuki, którym zostało dokładnie 7, 3 lub 1 dzień albo które wygasają dziś;
 *  - magazyn.stock_low: progi, które od poprzedniego skanu spadły poniżej minimum.
 */
final class HubScan
{
    public const NOTIFY_DAYS = [0, 1, 3, 7];

    public function __construct(
        private readonly StockMonitor $monitor,
        private readonly EventFactory $events,
        private readonly EntityManagerInterface $em,
        private readonly LockFactory $locks,
    ) {}

    /** @return array{ran: bool, expiring: int, stockLow: int} liczby pozycji w zapisanych zdarzeniach */
    public function run(\DateTimeImmutable $now): array
    {
        $lock = $this->locks->createLock('hub-scan', 600);
        if (!$lock->acquire()) {
            return ['ran' => false, 'expiring' => 0, 'stockLow' => 0];
        }

        try {
            $now = $now->setTimezone(new \DateTimeZone('UTC'));

            return ['ran' => true, 'expiring' => $this->scanExpiring($now), 'stockLow' => $this->scanStockLow($now)];
        } finally {
            $lock->release();
        }
    }

    private function scanExpiring(\DateTimeImmutable $now): int
    {
        $today = $now->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y-m-d');
        $items = array_values(array_filter(
            $this->monitor->expiring($today),
            fn(array $item) => in_array($item['daysLeft'], self::NOTIFY_DAYS, true),
        ));
        if ($items === []) {
            return 0;
        }

        $id = 'expiring-' . str_replace('-', '', $today);
        if ($this->em->find(OutboxEvent::class, $id) !== null) {
            return 0;
        }

        $this->em->persist($this->events->expiring($id, $now, $items));
        $this->em->flush();

        return count($items);
    }

    private function scanStockLow(\DateTimeImmutable $now): int
    {
        $current = [];
        foreach ($this->monitor->lowStock() as $alert) {
            $current[$alert['key']] = $alert;
        }

        return $this->em->wrapInTransaction(function () use ($current, $now) {
            $known = [];
            foreach ($this->em->getRepository(AlertState::class)->findAll() as $state) {
                $known[$state->getKey()] = $state;
            }

            foreach ($known as $key => $state) {
                if (!isset($current[$key])) {
                    $this->em->remove($state);
                }
            }

            $newlyBelow = array_values(array_diff_key($current, $known));
            foreach ($newlyBelow as $alert) {
                $this->em->persist(new AlertState($alert['key'], $now));
            }

            if ($newlyBelow !== []) {
                usort($newlyBelow, fn(array $a, array $b) => strcmp((string) $a['name'], (string) $b['name']));
                $id = 'stock_low-' . $now->format('Ymd\THis\Z') . '-' . bin2hex(random_bytes(3));
                $this->em->persist($this->events->stockLow($id, $now, $newlyBelow));
            }
            $this->em->flush();

            return count($newlyBelow);
        });
    }
}
