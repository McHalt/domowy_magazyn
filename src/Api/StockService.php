<?php

namespace App\Api;

use App\Entity\Product;
use App\Entity\ProductHistory;
use App\Repository\ProductHistoryRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/** Dodawanie i zdejmowanie sztuk. Tabele produktów są MyISAM (bez transakcji), więc operacje na produkcie serializuje blokada bazy. */
final class StockService
{
    private const LOCK_TIMEOUT = 5;

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $em,
        private readonly ProductHistoryRepository $history,
    ) {}

    /** @return list<ProductHistory> */
    public function add(Product $product, int $quantity, int $costMinor, ?string $expirationDate, ?string $memberId): array
    {
        return $this->withLock($product->getId(), function () use ($product, $quantity, $costMinor, $expirationDate, $memberId) {
            $created = [];
            for ($i = 0; $i < $quantity; $i++) {
                $created[] = $this->history->addEntry($product, $costMinor, $expirationDate, false, $memberId);
            }
            $this->em->flush();

            return $created;
        });
    }

    /**
     * Najpierw sztuki o najbliższej dacie ważności (bez daty na końcu), potem najstarsze wpisy.
     *
     * @param array{date: ?string}|null $onlyDate null = dowolne sztuki; ['date' => X] = tylko z tą datą (X = null: bez daty)
     * @return list<int> id zdjętych wpisów
     */
    public function consume(int $productId, int $quantity, ?array $onlyDate, ?string $memberId): array
    {
        return $this->withLock($productId, function () use ($productId, $quantity, $onlyDate, $memberId) {
            $sql = 'SELECT id FROM products_history WHERE products_id = :product AND active = 1';
            $params = ['product' => $productId];
            if ($onlyDate !== null) {
                if ($onlyDate['date'] === null) {
                    $sql .= ' AND expiration_date IS NULL';
                } else {
                    $sql .= ' AND expiration_date = :date';
                    $params['date'] = $onlyDate['date'];
                }
            }
            $ids = array_map('intval', $this->connection->fetchFirstColumn(
                $sql . ' ORDER BY expiration_date IS NULL, expiration_date, id',
                $params,
            ));

            if (count($ids) < $quantity) {
                throw ApiProblem::insufficientStock(count($ids));
            }

            $ids = array_slice($ids, 0, $quantity);
            $affected = $this->connection->executeStatement(
                'UPDATE products_history SET active = 0, removed_by = ?, removed_at = ? WHERE active = 1 AND id IN (?)',
                [$memberId, (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'), $ids],
                [ParameterType::STRING, ParameterType::STRING, ArrayParameterType::INTEGER],
            );
            if ($affected !== count($ids)) {
                throw new \RuntimeException(sprintf('Zdjęto %d z %d sztuk produktu %d: równoległa zmiana poza API.', $affected, count($ids), $productId));
            }

            return $ids;
        });
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function withLock(int $productId, callable $operation): mixed
    {
        $name = 'magazyn_stock_' . $productId;
        if ((int) $this->connection->fetchOne('SELECT GET_LOCK(?, ?)', [$name, self::LOCK_TIMEOUT]) !== 1) {
            throw new \RuntimeException('Nie udało się uzyskać blokady produktu ' . $productId);
        }

        try {
            return $operation();
        } finally {
            $this->connection->executeStatement('DO RELEASE_LOCK(?)', [$name]);
        }
    }
}
