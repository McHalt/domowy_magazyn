<?php

namespace App\Repository;

use App\Entity\OutboxEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class OutboxEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OutboxEvent::class);
    }

    /** @return list<OutboxEvent> */
    public function findDue(\DateTimeImmutable $now, int $limit = 20): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.status = :pending')
            ->andWhere('e.nextAttemptAt <= :now')
            ->orderBy('e.createdAt', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->setMaxResults($limit)
            ->setParameter('pending', OutboxEvent::PENDING)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    /** Wysłane i nieudane wpisy starsze niż podany czas (porządkowanie kolejki). */
    public function deleteFinishedBefore(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('e')
            ->delete()
            ->where('e.status <> :pending')
            ->andWhere('e.createdAt < :before')
            ->setParameter('pending', OutboxEvent::PENDING)
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
