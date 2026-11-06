<?php

namespace App\Api;

use App\Entity\MinStockRule;
use App\Entity\Product;
use App\Entity\ProductHistory;
use App\Entity\ProductsGroup;
use App\Hub\Security\MemberRegistry;
use App\Repository\MinStockRuleRepository;
use App\Service\MinStockManager;
use App\Service\MinStockOverview;
use App\Service\NameNormalizer;

/** Zamienia encje na tablice w kształcie z docs/hub/openapi.yaml. */
final class Presenter
{
    public function __construct(
        private readonly MinStockManager $minStock,
        private readonly MinStockRuleRepository $rules,
        private readonly MemberRegistry $members,
        private readonly NameNormalizer $normalizer,
    ) {}

    /** @return array{product: array<int, array<string, MinStockRule>>, group: array<int, array<string, MinStockRule>>} */
    public function loadRules(): array
    {
        return $this->rules->findAllIndexed();
    }

    /** Nazwy i producenci zapisani starymi ścieżkami mają encje HTML (&amp;); API zwraca czysty tekst. */
    public static function text(?string $value): ?string
    {
        return $value === null ? null : html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function number(?float $value): int|float|null
    {
        if ($value === null) {
            return null;
        }
        $rounded = round($value, 3);

        return $rounded == floor($rounded) ? (int) $rounded : $rounded;
    }

    public static function productName(Product $product): ?string
    {
        return self::text($product->getFeaturesMap()['name'] ?? null);
    }

    public static function productProducer(Product $product): ?string
    {
        return self::text($product->getFeaturesMap()['producer'] ?? null);
    }

    /** Klucz sortowania: nazwa bez ogonków i wielkości liter, potem id. */
    public function sortKey(?string $name, int $id): string
    {
        return $this->normalizer->normalize((string) $name) . '|' . sprintf('%010d', $id);
    }

    public function product(Product $product, array $rules): array
    {
        $overview = $this->minStock->overviewWithRules($product, $rules['product'][$product->getId()] ?? []);
        $features = $product->getFeaturesMap();

        $costs = [];
        $lastCostId = 0;
        $lastCost = null;
        foreach ($product->getHistory() as $entry) {
            if ($entry->getCost() > 0) {
                $costs[] = $entry->getCost();
                if ($entry->getId() > $lastCostId) {
                    $lastCostId = $entry->getId();
                    $lastCost = $entry->getCost();
                }
            }
        }

        $groups = array_map(
            fn(ProductsGroup $g) => ['id' => $g->getId(), 'name' => self::text($g->getName())],
            $product->getGroups()->toArray(),
        );
        usort($groups, fn(array $a, array $b) => strcmp($this->sortKey($a['name'], $a['id']), $this->sortKey($b['name'], $b['id'])));

        return [
            'id' => $product->getId(),
            'ean' => $product->getEan(),
            'name' => self::text($features['name'] ?? null),
            'producer' => self::text($features['producer'] ?? null),
            'unit' => $product->getUnit()?->value,
            'amountPerPackage' => self::number($product->getAmountPerPackage()),
            'quantity' => $product->getQty(),
            'totalAmount' => self::number($overview->getAmount()),
            'lastCostMinor' => $lastCost,
            'lowestCostMinor' => $costs === [] ? null : min($costs),
            'groups' => $groups,
            'minStock' => $this->minStockArray($overview),
            'belowMinimum' => $overview->isPackagesBelow() || $overview->isAmountBelow(),
        ];
    }

    public function productDetail(Product $product, array $rules): array
    {
        $active = array_values(array_filter($product->getHistory()->toArray(), fn(ProductHistory $h) => $h->isActive()));

        return $this->product($product, $rules) + ['items' => $this->items($active)];
    }

    /**
     * @param list<ProductHistory> $entries
     * @return list<array>
     */
    public function items(array $entries): array
    {
        usort($entries, function (ProductHistory $a, ProductHistory $b) {
            return [$a->getExpirationDate() === null, $a->getExpirationDateString(), $a->getId()]
                <=> [$b->getExpirationDate() === null, $b->getExpirationDateString(), $b->getId()];
        });

        $names = $this->members->names(array_values(array_filter(array_map(fn(ProductHistory $h) => $h->getAddedBy(), $entries))));

        return array_map(fn(ProductHistory $h) => [
            'id' => $h->getId(),
            'costMinor' => $h->getCost(),
            'addedOn' => $h->getDateAdded()->format('Y-m-d'),
            'expirationDate' => $h->getExpirationDateString(),
            'addedBy' => $h->getAddedBy() === null ? null : ['id' => $h->getAddedBy(), 'name' => $names[$h->getAddedBy()] ?? 'Domownik'],
        ], $entries);
    }

    public function group(ProductsGroup $group, array $rules): array
    {
        $overview = $this->minStock->overviewWithRules($group, $rules['group'][$group->getId()] ?? []);

        return [
            'id' => $group->getId(),
            'name' => self::text($group->getName()),
            'unit' => $group->getUnit()?->value,
            'quantity' => $group->getQty(),
            'totalAmount' => self::number($overview->getAmount()),
            'productCount' => $group->getProducts()->count(),
            'minStock' => $this->minStockArray($overview),
            'belowMinimum' => $overview->isPackagesBelow() || $overview->isAmountBelow(),
        ];
    }

    public function groupDetail(ProductsGroup $group, array $rules): array
    {
        $counted = $group->getProductsCountedInAmount();
        $products = array_map(fn(Product $p) => [
            'id' => $p->getId(),
            'name' => self::productName($p),
            'producer' => self::productProducer($p),
            'quantity' => $p->getQty(),
            'countedInAmount' => in_array($p, $counted, true),
        ], $group->getProducts()->toArray());
        usort($products, fn(array $a, array $b) => strcmp($this->sortKey($a['name'], $a['id']), $this->sortKey($b['name'], $b['id'])));

        return $this->group($group, $rules) + ['products' => $products];
    }

    private function minStockArray(MinStockOverview $overview): array
    {
        return [
            'packages' => $overview->minPackages,
            'amount' => self::number($overview->getMinAmount()),
        ];
    }
}
