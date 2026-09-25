<?php

namespace App\Entity;

use App\Repository\FeatureRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FeatureRepository::class)]
#[ORM\Table(name: 'features')]
class Feature
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(length: 200)]
    private string $namePl;

    public function getId(): int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getNamePl(): string { return $this->namePl; }
    public function setNamePl(string $namePl): static { $this->namePl = $namePl; return $this; }
}
