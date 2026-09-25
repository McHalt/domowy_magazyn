<?php

namespace App\Entity;

enum Unit: string
{
    case Piece = 'szt';
    case Gram = 'g';
    case Kilogram = 'kg';
    case Millilitre = 'ml';
    case Litre = 'l';

    public static function fromLabel(?string $label): ?self
    {
        return $label === null ? null : self::tryFrom(mb_strtolower(trim($label)));
    }

    public function base(): self
    {
        return match ($this) {
            self::Kilogram => self::Gram,
            self::Litre => self::Millilitre,
            default => $this,
        };
    }

    public function toBaseFactor(): int
    {
        return match ($this) {
            self::Kilogram, self::Litre => 1000,
            default => 1,
        };
    }

    public function isCompatibleWith(self $other): bool
    {
        return $this->base() === $other->base();
    }
}
