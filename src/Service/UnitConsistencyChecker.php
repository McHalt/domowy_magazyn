<?php

namespace App\Service;

use App\Entity\Product;
use App\Entity\ProductsGroup;
use App\Entity\Unit;

/**
 * Produkt w grupie musi mieć jednostkę zgodną z jednostką grupy (g↔kg, ml↔l), o ile obie są ustawione —
 * inaczej łączna ilość grupy i stan minimalny w jednostce nie miałyby sensu.
 */
final class UnitConsistencyChecker
{
    /**
     * @param iterable<ProductsGroup> $groups
     * @return string[]
     */
    public function checkProduct(?Unit $productUnit, iterable $groups): array
    {
        if ($productUnit === null) {
            return [];
        }

        $errors = [];
        foreach ($groups as $group) {
            $groupUnit = $group->getUnit();
            if ($groupUnit !== null && !$productUnit->isCompatibleWith($groupUnit)) {
                $errors[] = sprintf(
                    'Produkt w jednostce „%s” nie może należeć do grupy „%s” (jednostka „%s”).',
                    $productUnit->value,
                    $group->getName(),
                    $groupUnit->value,
                );
            }
        }

        return $errors;
    }

    /** @return string[] */
    public function checkGroupUnit(ProductsGroup $group, ?Unit $groupUnit): array
    {
        if ($groupUnit === null) {
            return [];
        }

        $errors = [];
        foreach ($group->getProducts() as $product) {
            /** @var Product $product */
            $productUnit = $product->getUnit();
            if ($productUnit !== null && !$productUnit->isCompatibleWith($groupUnit)) {
                $errors[] = sprintf(
                    'Nie można ustawić jednostki „%s”: produkt „%s” jest w jednostce „%s”.',
                    $groupUnit->value,
                    trim(($product->getFeaturesMap()['producer'] ?? '') . ' ' . ($product->getFeaturesMap()['name'] ?? $product->getEan())),
                    $productUnit->value,
                );
            }
        }

        return $errors;
    }
}
