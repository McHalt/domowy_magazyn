<?php

namespace App\Repository;

use App\Entity\Feature;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class FeatureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Feature::class);
    }

    /** Zwraca mapę: ['name' => 'Nazwa PL', ...] */
    public function findAllAsMap(): array
    {
        $map = [];
        foreach ($this->findAll() as $feature) {
            $map[$feature->getName()] = $feature->getNamePl();
        }
        return $map;
    }
}
