<?php

namespace App\Dto;

class CurrencyOutputDto
{
    public function __construct(
        public string $code,   // USD
        public string $symbol, // $
        public string $name,   // US Dollar
        public float $rate,    // 1.08
    ) {}
}
