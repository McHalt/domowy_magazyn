<?php

namespace App\Api;

use App\Entity\Product;
use App\Entity\ProductsGroup;
use App\Entity\ProductToFeature;
use App\Entity\Unit;
use App\Repository\FeatureRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductsGroupRepository;
use App\Service\MinStockManager;
use App\Service\UnitConsistencyChecker;
use Doctrine\ORM\EntityManagerInterface;

/** Zapisy produktów i grup dla API; walidacja domenowa tak samo jak w aplikacji webowej (jednostki, grupy). */
final class CatalogService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProductRepository $products,
        private readonly ProductsGroupRepository $groups,
        private readonly FeatureRepository $features,
        private readonly UnitConsistencyChecker $unitChecker,
        private readonly MinStockManager $minStock,
    ) {}

    /** @param array{ean: string, name: string, producer: ?string, unit: ?Unit, amountPerPackage: ?float, groupIds: list<int>} $data */
    public function createProduct(array $data): int
    {
        $existing = $this->products->findOneBy(['ean' => $data['ean']]);
        if ($existing !== null) {
            throw ApiProblem::eanTaken($existing->getId());
        }

        if ($data['amountPerPackage'] !== null && $data['unit'] === null) {
            throw ApiProblem::validation([['field' => 'amountPerPackage', 'message' => 'Ilość w opakowaniu wymaga ustawionej jednostki']]);
        }
        $groups = $this->resolveGroups($data['groupIds']);
        $this->assertUnitsConsistent($data['unit'], $groups);

        return $this->em->wrapInTransaction(function () use ($data, $groups) {
            $product = (new Product())->setEan($data['ean']);
            $this->em->persist($product);
            $this->em->flush();

            $this->writeFeature($product, 'name', $data['name']);
            $this->writeFeature($product, 'producer', $data['producer']);
            $this->writeFeature($product, 'unit', $data['unit']?->value);
            $this->writeFeature($product, 'qty in package', self::formatAmount($data['amountPerPackage']));
            foreach ($groups as $group) {
                $product->getGroups()->add($group);
            }
            $this->em->flush();

            return $product->getId();
        });
    }

    /**
     * @param array<string, mixed> $changes tylko pola obecne w żądaniu: name, producer, unit, amountPerPackage, groupIds
     */
    public function updateProduct(Product $product, array $changes): void
    {
        $oldUnit = $product->getUnit();
        $newUnit = array_key_exists('unit', $changes) ? $changes['unit'] : $oldUnit;

        $errors = [];
        if (array_key_exists('unit', $changes) && $newUnit === null && !array_key_exists('amountPerPackage', $changes) && $product->getAmountPerPackage() !== null) {
            $errors[] = ['field' => 'unit', 'message' => 'Usuń najpierw ilość w opakowaniu (amountPerPackage: null)'];
        }
        if (($changes['amountPerPackage'] ?? null) !== null && $newUnit === null) {
            $errors[] = ['field' => 'amountPerPackage', 'message' => 'Ilość w opakowaniu wymaga ustawionej jednostki'];
        }

        $groups = array_key_exists('groupIds', $changes)
            ? $this->resolveGroups($changes['groupIds'], $errors)
            : $product->getGroups()->toArray();
        if ($errors === []) {
            try {
                $this->assertUnitsConsistent($newUnit, $groups);
            } catch (ApiProblem $problem) {
                $errors = $problem->errors;
            }
        }
        if ($errors !== []) {
            throw ApiProblem::validation($errors);
        }

        $this->em->wrapInTransaction(function () use ($product, $changes, $groups, $oldUnit, $newUnit) {
            if (array_key_exists('name', $changes)) {
                $this->writeFeature($product, 'name', $changes['name']);
            }
            if (array_key_exists('producer', $changes)) {
                $this->writeFeature($product, 'producer', $changes['producer']);
            }
            if (array_key_exists('unit', $changes)) {
                $this->writeFeature($product, 'unit', $newUnit?->value);
                $this->minStock->dropProductAmountRuleOnUnitChange($product, $oldUnit, $newUnit);
            }
            if (array_key_exists('amountPerPackage', $changes)) {
                $this->writeFeature($product, 'qty in package', self::formatAmount($changes['amountPerPackage']));
            }
            if (array_key_exists('groupIds', $changes)) {
                foreach ($product->getGroups()->toArray() as $current) {
                    if (!in_array($current, $groups, true)) {
                        $product->getGroups()->removeElement($current);
                    }
                }
                foreach ($groups as $group) {
                    if (!$product->getGroups()->contains($group)) {
                        $product->getGroups()->add($group);
                    }
                }
            }
            $this->em->flush();
        });
    }

    public function createGroup(string $name, ?Unit $unit): int
    {
        $group = (new ProductsGroup())->setName($name)->setUnit($unit);
        $this->groups->save($group);

        return $group->getId();
    }

    /** @param array<string, mixed> $changes name i/lub unit */
    public function updateGroup(ProductsGroup $group, array $changes): void
    {
        if (array_key_exists('unit', $changes)) {
            $errors = $this->minStock->changeGroupUnit($group, $changes['unit']);
            if ($errors !== []) {
                throw ApiProblem::validation(array_map(fn(string $m) => ['field' => 'unit', 'message' => $m], array_values($errors)));
            }
        }
        if (array_key_exists('name', $changes)) {
            $group->setName($changes['name']);
            $this->em->flush();
        }
    }

    /** @param array<string, string> $errors klucze `packages` i `amount` z MinStockManager */
    public function setMinStock(Product|ProductsGroup $target, ?int $packages, ?float $amount): void
    {
        $errors = $this->minStock->update(
            $target,
            $packages === null ? '' : (string) $packages,
            $amount === null ? '' : self::formatAmount($amount),
        );
        if ($errors !== []) {
            throw ApiProblem::validation(array_map(
                fn(string $field, string $message) => ['field' => $field, 'message' => $message],
                array_keys($errors),
                array_values($errors),
            ));
        }
    }

    public static function formatAmount(?float $amount): ?string
    {
        return $amount === null ? null : rtrim(rtrim(number_format($amount, 3, '.', ''), '0'), '.');
    }

    /**
     * @param list<int> $ids
     * @param list<array{field: string, message: string}>|null $errors gdy podane, błędy trafiają tam zamiast do wyjątku
     * @return list<ProductsGroup>
     */
    private function resolveGroups(array $ids, ?array &$errors = null): array
    {
        $groups = [];
        $missing = [];
        foreach ($ids as $id) {
            $group = $this->groups->find($id);
            $group === null ? $missing[] = $id : $groups[] = $group;
        }

        if ($missing !== []) {
            $error = ['field' => 'groupIds', 'message' => 'Nieznane grupy: ' . implode(', ', $missing)];
            if ($errors === null) {
                throw ApiProblem::validation([$error]);
            }
            $errors[] = $error;
        }

        return $groups;
    }

    /** @param iterable<ProductsGroup> $groups */
    private function assertUnitsConsistent(?Unit $unit, iterable $groups): void
    {
        $messages = $this->unitChecker->checkProduct($unit, $groups);
        if ($messages !== []) {
            throw ApiProblem::validation(array_map(fn(string $m) => ['field' => 'unit', 'message' => $m], $messages));
        }
    }

    private function writeFeature(Product $product, string $featureName, ?string $value): void
    {
        $feature = $this->features->findOneBy(['name' => $featureName])
            ?? throw new \LogicException(sprintf('Brak cechy "%s" w tabeli features.', $featureName));

        foreach ($product->getProductFeatures() as $existing) {
            if ($existing->getFeature() === $feature) {
                if ($value === null) {
                    $product->getProductFeatures()->removeElement($existing);
                    $this->em->remove($existing);
                } else {
                    $existing->setValue($value);
                }

                return;
            }
        }

        if ($value !== null) {
            $link = (new ProductToFeature())->setProduct($product)->setFeature($feature)->setValue($value);
            $product->getProductFeatures()->add($link);
            $this->em->persist($link);
        }
    }
}
