<?php

namespace App\Entity;

use App\Repository\ProductsGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductsGroupRepository::class)]
#[ORM\Table(name: 'products_groups')]
class ProductsGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 100)]
    private string $name;

    /** Jednostka, w której liczona jest łączna ilość grupy (np. kawa w g) */
    #[ORM\Column(length: 10, nullable: true, enumType: Unit::class)]
    private ?Unit $unit = null;

    #[ORM\ManyToMany(targetEntity: Product::class, mappedBy: 'groups')]
    private Collection $products;

    public function __construct()
    {
        $this->products = new ArrayCollection();
    }

    public function getId(): int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getUnit(): ?Unit { return $this->unit; }
    public function setUnit(?Unit $unit): static { $this->unit = $unit; return $this; }

    public function getProducts(): Collection { return $this->products; }

    /** Łączna liczba opakowań wszystkich produktów grupy */
    public function getQty(): int
    {
        return array_sum($this->products->map(fn(Product $p) => $p->getQty())->toArray());
    }

    /** Produkty, których ilość da się przeliczyć na jednostkę grupy */
    public function getProductsCountedInAmount(): array
    {
        if ($this->unit === null) {
            return [];
        }

        return $this->products->filter(
            fn(Product $p) => $p->getBaseAmount() !== null && $p->getUnit()->isCompatibleWith($this->unit)
        )->getValues();
    }

    /** Produkty pominięte w łącznej ilości: bez jednostki/ilości w opakowaniu albo w innej jednostce */
    public function getProductsSkippedInAmount(): array
    {
        $counted = $this->getProductsCountedInAmount();

        return $this->products->filter(fn(Product $p) => !in_array($p, $counted, true))->getValues();
    }

    /** Łączna ilość w jednostce bazowej jednostki grupy; null gdy grupa nie ma jednostki */
    public function getBaseAmount(): ?float
    {
        if ($this->unit === null) {
            return null;
        }

        return array_sum(array_map(fn(Product $p) => $p->getBaseAmount(), $this->getProductsCountedInAmount()));
    }
}
