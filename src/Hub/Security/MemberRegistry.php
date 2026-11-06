<?php

namespace App\Hub\Security;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/** Lokalny rekord domownika zakładany przy pierwszym użyciu (just-in-time); nazwa jest odświeżana z tokenu. */
final class MemberRegistry
{
    private const MAX_NAME = 255;

    public function __construct(private readonly Connection $connection) {}

    public static function cleanName(?string $name): string
    {
        $clean = trim(preg_replace('/[\p{C}]+/u', ' ', (string) $name) ?? '');

        return $clean === '' ? 'Domownik' : mb_substr($clean, 0, self::MAX_NAME);
    }

    public function touch(string $sub, ?string $name): void
    {
        $name = self::cleanName($name);
        $current = $this->connection->fetchOne('SELECT name FROM members WHERE id = ?', [$sub]);
        if ($current === $name) {
            return;
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->connection->executeStatement(
            'INSERT INTO members (id, name, created_at, updated_at) VALUES (:id, :name, :now, :now)
             ON DUPLICATE KEY UPDATE name = VALUES(name), updated_at = VALUES(updated_at)',
            ['id' => $sub, 'name' => $name, 'now' => $now],
        );
    }

    /**
     * @param list<string> $ids
     * @return array<string, string> id => nazwa
     */
    public function names(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        return $this->connection->fetchAllKeyValue(
            'SELECT id, name FROM members WHERE id IN (?)',
            [$ids],
            [ArrayParameterType::STRING],
        );
    }
}
