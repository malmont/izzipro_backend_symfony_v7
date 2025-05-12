<?php

namespace App\Dto;

class RateOptionDto
{
    public string $carrier;       // ex. "Canada Post"
    public string $service;       // ex. "Xpresspost"
    public float  $price;         // tarif
    public string $currency;      // ex. "CAD"
    public int    $estimatedDays; // délai en jours

    public function __construct(string $carrier, string $service, float $price, string $currency, int $estimatedDays)
    {
        $this->carrier       = $carrier;
        $this->service       = $service;
        $this->price         = $price;
        $this->currency      = $currency;
        $this->estimatedDays = $estimatedDays;
    }
}
