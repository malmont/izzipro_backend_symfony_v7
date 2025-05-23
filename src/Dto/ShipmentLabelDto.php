<?php

namespace App\Dto;

class ShipmentLabelDto
{
    public string $labelUrl;
    public string $trackingCode;

    public function __construct(string $labelUrl, string $trackingCode)
    {
        $this->labelUrl     = $labelUrl;
        $this->trackingCode = $trackingCode;
    }
}
