<?php

namespace App\Dto;

class RateSummaryDto
{
    public string $carrier;
    public string $service;
    public float  $totalPrice;
    public string $currency;
    public int    $parcelCount;
    public int    $estimatedDays; 

    public function __construct(
        string $carrier,
        string $service,
        float  $totalPrice,
        string $currency,
        int    $parcelCount,
        int    $estimatedDays    
    ) {
        $this->carrier       = $carrier;
        $this->service       = $service;
        $this->totalPrice    = $totalPrice;
        $this->currency      = $currency;
        $this->parcelCount   = $parcelCount;
        $this->estimatedDays = $estimatedDays;
    }
}
