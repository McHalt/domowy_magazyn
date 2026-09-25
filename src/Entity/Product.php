<?php

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'products')]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(type: 'bigint')]
    private string $ean;

    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductHistory::class)]
    private Collection $history;

    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductToFeature::class)]
    private Collection $productFeatures;

    #[ORM\ManyToMany(targetEntity: ProductsGroup::class, inversedBy: 'products')]
    #[ORM\JoinTable(
        name: 'products_to_products_groups',
        joinColumns: [new ORM\JoinColumn(name: 'product_id')],
        inverseJoinColumns: [new ORM\JoinColumn(name: 'products_group_id')]
    )]
    private Collection $groups;

    public function __construct()
    {
        $this->history = new ArrayCollection();
        $this->productFeatures = new ArrayCollection();
        $this->groups = new ArrayCollection();
    }

    public function getId(): int { return $this->id; }

    public function getEan(): string { return $this->ean; }
    public function setEan(string $ean): static { $this->ean = $ean; return $this; }

    public function getHistory(): Collection { return $this->history; }
    public function getProductFeatures(): Collection { return $this->productFeatures; }
    public function getGroups(): Collection { return $this->groups; }

    /** Liczba sztuk na stanie (aktywne wpisy w historii) */
    public function getQty(): int
    {
        return $this->history->filter(fn(ProductHistory $h) => $h->isActive())->count();
    }

    /** Najniższy koszt zakupu (w złotych) wśród wpisów z kosztem > 0 */
    public function getLowestCost(): float
    {
        $costs = $this->history
            ->filter(fn(ProductHistory $h) => $h->getCost() > 0)
            ->map(fn(ProductHistory $h) => $h->getCost())
            ->toArray();

        return empty($costs) ? 0.0 : min($costs) / 100;
    }

    /** Ostatni koszt zakupu (w złotych) — wpis z najwyższym ID z kosztem > 0 */
    public function getLastCost(): float
    {
        $withCost = $this->history
            ->filter(fn(ProductHistory $h) => $h->getCost() > 0)
            ->toArray();

        if (empty($withCost)) {
            return 0.0;
        }

        usort($withCost, fn(ProductHistory $a, ProductHistory $b) => $b->getId() - $a->getId());

        return $withCost[0]->getCost() / 100;
    }

    /**
     * Aktywne produkty w formacie zgodnym ze starym API.
     * @return array<int, array{historyId: int, cost: float, dateAdded: string, expirationDate: string|null}>
     */
    public function getActiveProducts(): array
    {
        $result = [];
        foreach ($this->history as $h) {
            if ($h->isActive()) {
                $result[] = [
                    'historyId' => $h->getId(),
                    'cost' => $h->getCostDecimal(),
                    'dateAdded' => $h->getDateAdded()->format('Y-m-d'),
                    'expirationDate' => $h->getExpirationDateString(),
                ];
            }
        }
        return $result;
    }

    /**
     * Mapa cech produktu: ['name' => 'Makaron Penne', 'producer' => 'Barilla', ...]
     * Kompatybilne ze starą składnią Twig: product.featuresMap.producer
     */
    public function getFeaturesMap(): array
    {
        $map = [];
        foreach ($this->productFeatures as $pf) {
            $map[$pf->getFeature()->getName()] = $pf->getValue();
        }
        return $map;
    }

    /**
     * Mapa grup produktu: [id => name, ...]
     * Kompatybilne ze starą logiką.
     */
    public function getGroupsMap(): array
    {
        $map = [];
        foreach ($this->groups as $group) {
            $map[$group->getId()] = $group->getName();
        }
        return $map;
    }

    /**
     * Aktywne wpisy pogrupowane wg daty ważności (unikalne daty).
     * Używane w RemoveProduct do wyboru daty.
     */
    public function getUniqueExpirationDates(): array
    {
        $dates = [];
        foreach ($this->history as $h) {
            if ($h->isActive()) {
                $dates[] = $h->getExpirationDateString();
            }
        }
        return array_unique($dates);
    }

    /**
     * Deaktywuje sztuki o danej dacie ważności (lub NULL).
     * Zwraca liczbę zdeaktywowanych wpisów.
     */
    public function deactivateByExpirationDate(?string $date, int $limit = 1): int
    {
        $deactivated = 0;
        foreach ($this->history as $h) {
            if ($deactivated >= $limit) break;
            if (!$h->isActive()) continue;
            if ($h->getExpirationDateString() === $date) {
                $h->setActive(0);
                $deactivated++;
            }
        }
        return $deactivated;
    }
}
