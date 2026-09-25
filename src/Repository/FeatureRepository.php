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

    /**
     * Nazwa cechy z nazwy pola formularza bez prefiksu `feature_`. PHP zamienia spacje i kropki
     * w nazwach pól na `_` (`feature_qty in package` przychodzi jako `feature_qty_in_package`).
     */
    public function resolveFormFieldName(string $fieldName): string
    {
        foreach ($this->findAll() as $feature) {
            if (str_replace([' ', '.'], '_', $feature->getName()) === $fieldName) {
                return $feature->getName();
            }
        }

        return $fieldName;
    }
}
