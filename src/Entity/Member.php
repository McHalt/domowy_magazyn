<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Domownik z huba; kluczem jest `sub` (UUID), nazwa jest tylko do wyświetlania. */
#[ORM\Entity]
#[ORM\Table(name: 'members')]
class Member
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, string $name)
    {
        $this->id = $id;
        $this->name = $name;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): string { return $this->id; }
    public function getName(): string { return $this->name; }

    public function rename(string $name): void
    {
        if ($name !== $this->name) {
            $this->name = $name;
            $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }
    }
}
