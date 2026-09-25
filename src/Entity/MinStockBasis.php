<?php

namespace App\Entity;

enum MinStockBasis: string
{
    /** Liczba opakowań na stanie */
    case Packages = 'ITEMS';
    /** Ilość w jednostce bazowej (szt, g, ml) — opakowania × „ilość w opakowaniu” */
    case Amount = 'QUANTITY';
}
