<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Produkt albo grupa, która przy ostatnim skanie była poniżej stanu minimalnego (po jednym wierszu na próg). */
#[ORM\Entity]
#[ORM\Table(name: 'hub_alert_state')]
class AlertState
{
    #[ORM\Id]
    #[ORM\Column(name: 'alert_key', length: 64)]
    private string $key;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $since;

    public function __construct(string $key, \DateTimeImmutable $since)
    {
        $this->key = $key;
        $this->since = $since;
    }

    public function getKey(): string { return $this->key; }
}
