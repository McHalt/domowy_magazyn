<?php

namespace App\Service;

use App\Entity\MinStockBasis;
use App\Entity\MinStockRule;
use App\Entity\Product;
use App\Entity\ProductsGroup;
use App\Entity\Unit;
use App\Repository\MinStockRuleRepository;
use Doctrine\ORM\EntityManagerInterface;

final class MinStockManager
{
    private const MAX_VALUE = 1_000_000_000;

    public function __construct(
        private readonly MinStockRuleRepository $ruleRepo,
        private readonly UnitConsistencyChecker $unitChecker,
        private readonly EntityManagerInterface $em,
    ) {}

    public function overview(Product|ProductsGroup $target): MinStockOverview
    {
        $rules = $this->ruleRepo->findForTarget($target);

        return new MinStockOverview(
            packages: $target->getQty(),
            minPackages: ($rules[MinStockBasis::Packages->value] ?? null)?->getMinStock(),
            unit: $target->getUnit(),
            baseAmount: $target->getBaseAmount(),
            minBaseAmount: ($rules[MinStockBasis::Amount->value] ?? null)?->getMinStock(),
        );
    }

    /**
     * Minimum ilości podaje się w jednostce produktu (cecha `unit`) albo grupy. Pusty input usuwa regułę,
     * null (pole nie przyszło — wyłączone w formularzu) zostawia ją bez zmian.
     *
     * @return string[] błędy walidacji — gdy niepuste, nic nie zostało zmienione
     */
    public function update(Product|ProductsGroup $target, string $minPackagesInput, ?string $minAmountInput): array
    {
        $errors = [];

        $minPackages = $this->parsePackages($minPackagesInput, $errors);
        $unit = $target instanceof ProductsGroup || $target->getBaseAmount() !== null ? $target->getUnit() : null;
        $minBaseAmount = $minAmountInput === null ? null : $this->parseAmount($minAmountInput, $unit, $target, $errors);

        if ($errors) {
            return $errors;
        }

        $rules = $this->ruleRepo->findForTarget($target);
        $this->applyRule($target, $rules, MinStockBasis::Packages, $minPackages);
        if ($minAmountInput !== null) {
            $this->applyRule($target, $rules, MinStockBasis::Amount, $minBaseAmount);
        }
        $this->em->flush();

        return [];
    }

    /**
     * Minimum ilości jest zapisane w jednostce bazowej, więc zmiana g↔kg go nie rusza; przy zmianie na
     * niezgodny wymiar albo usunięciu jednostki reguła ilości traci sens i jest usuwana.
     *
     * @return string[] błędy walidacji — gdy niepuste, nic nie zostało zmienione
     */
    public function changeGroupUnit(ProductsGroup $group, ?Unit $unit): array
    {
        $errors = $this->unitChecker->checkGroupUnit($group, $unit);
        if ($errors) {
            return $errors;
        }

        $oldUnit = $group->getUnit();
        if ($oldUnit !== null && ($unit === null || !$unit->isCompatibleWith($oldUnit))) {
            $amountRule = $this->ruleRepo->findForTarget($group)[MinStockBasis::Amount->value] ?? null;
            if ($amountRule) {
                $this->em->remove($amountRule);
            }
        }

        $group->setUnit($unit);
        $this->em->flush();

        return [];
    }

    /**
     * Wywoływane przy zapisie cech produktu. Nie robi flush — zapis cech i tak kończy się flushem.
     */
    public function dropProductAmountRuleOnUnitChange(Product $product, ?Unit $oldUnit, ?Unit $newUnit): void
    {
        if ($oldUnit === null || ($newUnit !== null && $newUnit->isCompatibleWith($oldUnit))) {
            return;
        }

        $amountRule = $this->ruleRepo->findForTarget($product)[MinStockBasis::Amount->value] ?? null;
        if ($amountRule) {
            $this->em->remove($amountRule);
        }
    }

    private function parsePackages(string $input, array &$errors): ?int
    {
        $input = trim($input);
        if ($input === '' || $input === '0') {
            return null;
        }

        if (!ctype_digit($input) || (int)$input > self::MAX_VALUE) {
            $errors[] = 'Minimalna liczba opakowań musi być dodatnią liczbą całkowitą.';
            return null;
        }

        return (int)$input;
    }

    private function parseAmount(string $input, ?Unit $unit, Product|ProductsGroup $target, array &$errors): ?int
    {
        $input = str_replace(',', '.', trim($input));
        if ($input === '' || (is_numeric($input) && (float)$input == 0)) {
            return null;
        }

        if ($unit === null) {
            $errors[] = $target instanceof ProductsGroup
                ? 'Aby ustawić minimalną ilość, wybierz jednostkę grupy.'
                : 'Aby ustawić minimalną ilość, uzupełnij w produkcie cechy „jednostka” i „ilość w opakowaniu”.';
            return null;
        }

        $baseAmount = is_numeric($input) ? (int)round((float)$input * $unit->toBaseFactor()) : 0;
        if ($baseAmount < 1 || $baseAmount > self::MAX_VALUE) {
            $errors[] = sprintf('Minimalna ilość musi być dodatnią liczbą (w %s).', $unit->value);
            return null;
        }

        return $baseAmount;
    }

    /** @param array<string, MinStockRule> $rules */
    private function applyRule(Product|ProductsGroup $target, array $rules, MinStockBasis $basis, ?int $value): void
    {
        $rule = $rules[$basis->value] ?? null;

        if ($value === null) {
            if ($rule) {
                $this->em->remove($rule);
            }
            return;
        }

        if (!$rule) {
            $rule = (new MinStockRule())->setMinStockBasis($basis);
            $target instanceof Product ? $rule->setProduct($target) : $rule->setGroup($target);
            $this->em->persist($rule);
        }

        $rule->setMinStock($value);
    }
}
