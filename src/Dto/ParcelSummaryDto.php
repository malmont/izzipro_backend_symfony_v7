<?php
namespace App\Dto;

class ParcelSummaryDto
{
    public int   $index;
    public float $weight;
    public float $length;
    public float $width;
    public float $height;

    public function __construct(int $index, float $weight, float $length, float $width, float $height)
    {
        $this->index  = $index;
        $this->weight = $weight;
        $this->length = $length;
        $this->width  = $width;
        $this->height = $height;
    }
}
