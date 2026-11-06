<?php

namespace App\Service;

use App\Api\Presenter;
use App\Entity\Product;
use App\Entity\ProductsGroup;
use App\Repository\MinStockRuleRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductsGroupRepository;

/** Co wymaga uwagi: kończąca się ważność i zapas poniżej minimum. Wspólne dla API (/alerts) i skanu zdarzeń. */
final class StockMonitor
{
    public const EXPIRY_WINDOW_DAYS = 7;

    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductsGroupRepository $groups,
        private readonly MinStockRuleRepository $rules,
        private readonly MinStockManager $minStock,
    ) {}

    /**
     * Sztuki na stanie z terminem ważności najwyżej `$withinDays` dni od `$today` (także po terminie),
     * zgrupowane po produkcie i dacie.
     *
     * @param string $today data kalendarzowa Y-m-d
     * @return list<array{productId: int, name: ?string, producer: ?string, ean: string, expirationDate: string, daysLeft: int, quantity: int}>
     */
    public function expiring(string $today, int $withinDays = self::EXPIRY_WINDOW_DAYS): array
    {
        $todayTs = strtotime($today . ' UTC');
        $grouped = [];

        foreach ($this->products->findAllWithRelations() as $product) {
            foreach ($product->getActiveProducts() as $entry) {
                $date = $entry['expirationDate'];
                if ($date === null) {
                    continue;
                }
                $daysLeft = intdiv(strtotime($date . ' UTC') - $todayTs, 86400);
                if ($daysLeft > $withinDays) {
                    continue;
                }

                $key = $product->getId() . '|' . $date;
                $grouped[$key] ??= [
                    'productId' => $product->getId(),
                    'name' => Presenter::productName($product),
                    'producer' => Presenter::productProducer($product),
                    'ean' => $product->getEan(),
                    'expirationDate' => $date,
                    'daysLeft' => $daysLeft,
                    'quantity' => 0,
                ];
                $grouped[$key]['quantity']++;
            }
        }

        $rows = array_values($grouped);
        usort($rows, fn(array $a, array $b) => [$a['expirationDate'], $a['productId']] <=> [$b['expirationDate'], $b['productId']]);

        return $rows;
    }

    /**
     * Każdy przekroczony próg (opakowania, ilość) osobno, dla produktów i grup.
     *
     * @return list<array{key: string, subject: string, subjectId: int, name: ?string, basis: string, current: int|float, minimum: int|float, unit: ?string}>
     */
    public function lowStock(): array
    {
        $rules = $this->rules->findAllIndexed();
        $alerts = [];

        $targets = [];
        foreach ($this->products->findAllWithRelations() as $product) {
            $targets[] = ['product', $product, Presenter::productName($product), $rules['product'][$product->getId()] ?? []];
        }
        foreach ($this->groups->findAllWithProducts() as $group) {
            $targets[] = ['group', $group, Presenter::text($group->getName()), $rules['group'][$group->getId()] ?? []];
        }

        foreach ($targets as [$subject, $target, $name, $targetRules]) {
            /** @var Product|ProductsGroup $target */
            $overview = $this->minStock->overviewWithRules($target, $targetRules);
            $base = ['subject' => $subject, 'subjectId' => $target->getId(), 'name' => $name];

            if ($overview->isPackagesBelow()) {
                $alerts[] = $base + [
                    'key' => sprintf('%s:%d:packages', $subject[0], $target->getId()),
                    'basis' => 'packages',
                    'current' => $overview->packages,
                    'minimum' => $overview->minPackages,
                    'unit' => null,
                ];
            }
            if ($overview->isAmountBelow()) {
                $alerts[] = $base + [
                    'key' => sprintf('%s:%d:amount', $subject[0], $target->getId()),
                    'basis' => 'amount',
                    'current' => Presenter::number($overview->getAmount()),
                    'minimum' => Presenter::number($overview->getMinAmount()),
                    'unit' => $overview->unit?->value,
                ];
            }
        }

        return $alerts;
    }
}
