<?php

namespace App\Repository;

use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /** Wszytkie produkty z wczytanymi relacjami (history + features + groups) — unika N+1 */
    public function findAllWithRelations(): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.history', 'h')->addSelect('h')
            ->leftJoin('p.productFeatures', 'pf')->addSelect('pf')
            ->leftJoin('pf.feature', 'f')->addSelect('f')
            ->leftJoin('p.groups', 'g')->addSelect('g')
            ->getQuery()
            ->getResult();
    }

    public function findByIdWithRelations(int $id): ?Product
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.history', 'h')->addSelect('h')
            ->leftJoin('p.productFeatures', 'pf')->addSelect('pf')
            ->leftJoin('pf.feature', 'f')->addSelect('f')
            ->leftJoin('p.groups', 'g')->addSelect('g')
            ->where('p.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByEanWithRelations(string $ean): ?Product
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.history', 'h')->addSelect('h')
            ->leftJoin('p.productFeatures', 'pf')->addSelect('pf')
            ->leftJoin('pf.feature', 'f')->addSelect('f')
            ->leftJoin('p.groups', 'g')->addSelect('g')
            ->where('p.ean = :ean')
            ->setParameter('ean', $ean)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(Product $product, bool $flush = true): void
    {
        $this->getEntityManager()->persist($product);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
