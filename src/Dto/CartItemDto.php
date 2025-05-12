<?php
namespace App\Dto;

class CartItemDto
{
    public int $productId;
    public int $quantity;

    public function __construct(int $productId, int $quantity)
    {
        $this->productId = $productId;
        $this->quantity  = $quantity;
    }
}
