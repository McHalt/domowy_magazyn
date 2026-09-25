<?php

namespace App\Repository;

use App\Entity\MinStockRule;
use App\Entity\Product;
use App\Entity\ProductsGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MinStockRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MinStockRule::class);
    }

    /** @return array<string, MinStockRule> klucz: MinStockBasis->value */
    public function findForTarget(Product|ProductsGroup $target): array
    {
        $rules = $this->findBy([$target instanceof Product ? 'product' : 'group' => $target]);

        $byBasis = [];
        foreach ($rules as $rule) {
            $byBasis[$rule->getMinStockBasis()->value] = $rule;
        }

        return $byBasis;
    }
}
