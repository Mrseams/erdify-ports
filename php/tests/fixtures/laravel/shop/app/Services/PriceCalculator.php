<?php

namespace App\Services;

final class PriceCalculator
{
    public function total(array $lines): int
    {
        return array_sum($lines);
    }
}
