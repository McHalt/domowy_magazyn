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

    /**
     * Wszystkie reguły jednym zapytaniem.
     *
     * @return array{product: array<int, array<string, MinStockRule>>, group: array<int, array<string, MinStockRule>>}
     */
    public function findAllIndexed(): array
    {
        $indexed = ['product' => [], 'group' => []];
        foreach ($this->findAll() as $rule) {
            if ($rule->getProduct() !== null) {
                $indexed['product'][$rule->getProduct()->getId()][$rule->getMinStockBasis()->value] = $rule;
            } elseif ($rule->getGroup() !== null) {
                $indexed['group'][$rule->getGroup()->getId()][$rule->getMinStockBasis()->value] = $rule;
            }
        }

        return $indexed;
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
