<?php

namespace App\Dto;

class ParcelDto
{
    public float $weight;  // en kg (réel vs volumétrique déjà calculé)
    public float $length;  // cm
    public float $width;   // cm
    public float $height;  // cm

    public function __construct(float $weight, float $length, float $width, float $height)
    {
        $this->weight = $weight;
        $this->length = $length;
        $this->width  = $width;
        $this->height = $height;
    }
}
