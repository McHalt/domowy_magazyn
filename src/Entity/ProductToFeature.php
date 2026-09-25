<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Tabela łącząca produkty z cechami + wartość cechy.
 * Composite PK: (products_id, features_id)
 */
#[ORM\Entity]
#[ORM\Table(name: 'products_to_features')]
class ProductToFeature
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'productFeatures')]
    #[ORM\JoinColumn(name: 'products_id', nullable: false)]
    private Product $product;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Feature::class)]
    #[ORM\JoinColumn(name: 'features_id', nullable: false)]
    private Feature $feature;

    #[ORM\Column(length: 255)]
    private string $value;

    public function getProduct(): Product { return $this->product; }
    public function setProduct(Product $product): static { $this->product = $product; return $this; }

    public function getFeature(): Feature { return $this->feature; }
    public function setFeature(Feature $feature): static { $this->feature = $feature; return $this; }

    public function getValue(): string { return $this->value; }
    public function setValue(string $value): static { $this->value = $value; return $this; }
}
