<?php

namespace App\Entity;

use App\Repository\ProductHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductHistoryRepository::class)]
#[ORM\Table(name: 'products_history')]
class ProductHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'history')]
    #[ORM\JoinColumn(name: 'products_id', nullable: false)]
    private Product $product;

    /** Koszt w groszach (x100) */
    #[ORM\Column]
    private int $cost;

    #[ORM\Column]
    private int $active;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private \DateTimeInterface $dateAdded;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $expirationDate = null;

    /** `sub` domownika z huba; bez klucza obcego (tabela MyISAM), NULL dla wpisów sprzed integracji i z legacy API */
    #[ORM\Column(length: 36, nullable: true)]
    private ?string $addedBy = null;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $removedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $removedAt = null;

    public function getId(): int { return $this->id; }

    public function getProduct(): Product { return $this->product; }
    public function setProduct(Product $product): static { $this->product = $product; return $this; }

    public function getCost(): int { return $this->cost; }
    public function getCostDecimal(): float { return $this->cost / 100; }
    public function setCost(int $cost): static { $this->cost = $cost; return $this; }

    public function isActive(): bool { return $this->active === 1; }
    public function getActive(): int { return $this->active; }
    public function setActive(int $active): static { $this->active = $active; return $this; }

    public function getDateAdded(): \DateTimeInterface { return $this->dateAdded; }
    public function setDateAdded(\DateTimeInterface $dateAdded): static { $this->dateAdded = $dateAdded; return $this; }

    public function getExpirationDate(): ?\DateTimeInterface { return $this->expirationDate; }
    public function getExpirationDateString(): ?string
    {
        return $this->expirationDate?->format('Y-m-d');
    }
    public function setExpirationDate(?\DateTimeInterface $expirationDate): static
    {
        $this->expirationDate = $expirationDate;
        return $this;
    }

    public function getAddedBy(): ?string { return $this->addedBy; }
    public function setAddedBy(?string $addedBy): static { $this->addedBy = $addedBy; return $this; }

    public function getRemovedBy(): ?string { return $this->removedBy; }

    public function remove(?string $memberId): static
    {
        $this->active = 0;
        $this->removedBy = $memberId;
        $this->removedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this;
    }
}
