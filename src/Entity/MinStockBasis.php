<?php

namespace App\Entity;

enum MinStockBasis: string
{
    case Items = 'ITEMS';
    case Quantity = 'QUANTITY';
}
