<?php

namespace App\Repository;

use App\Entity\ProductsGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProductsGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductsGroup::class);
    }

    public function findAllWithProducts(): array
    {
        return $this->createQueryBuilder('g')
            ->leftJoin('g.products', 'p')->addSelect('p')
            ->getQuery()
            ->getResult();
    }

    public function findByIdWithProducts(int $id): ?ProductsGroup
    {
        return $this->createQueryBuilder('g')
            ->leftJoin('g.products', 'p')->addSelect('p')
            ->leftJoin('p.history', 'h')->addSelect('h')
            ->leftJoin('p.productFeatures', 'pf')->addSelect('pf')
            ->leftJoin('pf.feature', 'f')->addSelect('f')
            ->where('g.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(ProductsGroup $group, bool $flush = true): void
    {
        $this->getEntityManager()->persist($group);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
