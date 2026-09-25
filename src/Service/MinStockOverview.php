<?php

namespace App\Service;

use App\Entity\Unit;

/**
 * Stan vs. stan minimalny produktu albo grupy. Ilości trzymane w jednostce bazowej
 * (szt, g, ml), gettery bez „Base” zwracają je w jednostce wyświetlania ($unit).
 */
final class MinStockOverview
{
    public function __construct(
        public readonly int $packages,
        public readonly ?int $minPackages,
        public readonly ?Unit $unit,
        public readonly ?float $baseAmount,
        public readonly ?int $minBaseAmount,
    ) {}

    public function hasAmount(): bool
    {
        return $this->unit !== null && $this->baseAmount !== null;
    }

    public function getAmount(): ?float
    {
        return $this->hasAmount() ? $this->baseAmount / $this->unit->toBaseFactor() : null;
    }

    public function getMinAmount(): ?float
    {
        return $this->unit !== null && $this->minBaseAmount !== null
            ? $this->minBaseAmount / $this->unit->toBaseFactor()
            : null;
    }

    /** Zapisane minimum ilości, którego nie da się sprawdzić (produkt/grupa straciła jednostkę) */
    public function hasOrphanedMinAmount(): bool
    {
        return $this->minBaseAmount !== null && !$this->hasAmount();
    }

    public function isPackagesBelow(): bool
    {
        return $this->minPackages !== null && $this->packages < $this->minPackages;
    }

    public function isAmountBelow(): bool
    {
        return $this->hasAmount() && $this->minBaseAmount !== null && $this->baseAmount < $this->minBaseAmount;
    }
}
