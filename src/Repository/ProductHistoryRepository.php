<?php

namespace App\Repository;

use App\Entity\Product;
use App\Entity\ProductHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProductHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductHistory::class);
    }

    public function addEntry(Product $product, int $costGrosze, ?string $expirationDate, bool $flush = true): ProductHistory
    {
        $history = new ProductHistory();
        $history->setProduct($product);
        $history->setCost($costGrosze);
        $history->setActive(1);
        $history->setDateAdded(new \DateTime());
        if ($expirationDate) {
            $history->setExpirationDate(new \DateTime($expirationDate));
        }

        $this->getEntityManager()->persist($history);
        if ($flush) {
            $this->getEntityManager()->flush();
        }

        return $history;
    }

    public function save(ProductHistory $history, bool $flush = true): void
    {
        $this->getEntityManager()->persist($history);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
