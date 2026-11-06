<?php

namespace App\Service;

use App\Api\Presenter;
use App\Repository\ProductRepository;
use App\Repository\ProductsGroupRepository;

/** Dopasowanie po dokładnej, znormalizowanej nazwie: grupa ma pierwszeństwo przed produktami. */
final class StockLookup
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductsGroupRepository $groups,
        private readonly NameNormalizer $normalizer,
    ) {}

    /**
     * @param bool $decodeEntities porównuj nazwy po zamianie encji HTML (&amp;) na znaki, tak jak zwraca je API v1
     * @return array{type: ?string, matches: list<array{id: int, name: string, qty: int}>}
     */
    public function find(string $name, bool $decodeEntities = false): array
    {
        $needle = $this->normalizer->normalize($name);
        if ($needle === '') {
            return ['type' => null, 'matches' => []];
        }
        $label = fn(?string $value): string => (string) ($decodeEntities ? Presenter::text($value) : $value);

        $matches = [];
        foreach ($this->groups->findAll() as $group) {
            if ($this->normalizer->normalize($label($group->getName())) === $needle) {
                $matches[] = ['id' => $group->getId(), 'name' => $label($group->getName()), 'qty' => $group->getQty()];
            }
        }
        if ($matches !== []) {
            return ['type' => 'group', 'matches' => $matches];
        }

        foreach ($this->products->findAllWithRelations() as $product) {
            $productName = $label($product->getFeaturesMap()['name'] ?? null);
            if ($this->normalizer->normalize($productName) === $needle) {
                $matches[] = ['id' => $product->getId(), 'name' => $productName, 'qty' => $product->getQty()];
            }
        }

        return ['type' => $matches !== [] ? 'product' : null, 'matches' => $matches];
    }
}
