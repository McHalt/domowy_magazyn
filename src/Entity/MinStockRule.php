<?php

namespace App\Entity;

use App\Repository\MinStockRuleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MinStockRuleRepository::class)]
#[ORM\Table(name: 'min_stock_rules')]
class MinStockRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', nullable: true)]
    private ?Product $product = null;

    #[ORM\ManyToOne(targetEntity: ProductsGroup::class)]
    #[ORM\JoinColumn(name: 'group_id', nullable: true)]
    private ?ProductsGroup $group = null;

    #[ORM\Column]
    private int $minStock;

    #[ORM\Column(type: 'string', enumType: MinStockBasis::class)]
    private MinStockBasis $minStockBasis;

    public function getId(): int { return $this->id; }
    public function getProduct(): ?Product { return $this->product; }
    public function setProduct(?Product $product): static { $this->product = $product; return $this; }
    public function getGroup(): ?ProductsGroup { return $this->group; }
    public function setGroup(?ProductsGroup $group): static { $this->group = $group; return $this; }
    public function getMinStock(): int { return $this->minStock; }
    public function setMinStock(int $minStock): static { $this->minStock = $minStock; return $this; }
    public function getMinStockBasis(): MinStockBasis { return $this->minStockBasis; }
    public function setMinStockBasis(MinStockBasis $minStockBasis): static { $this->minStockBasis = $minStockBasis; return $this; }
}
