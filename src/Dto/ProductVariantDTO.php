<?php
namespace App\Dto;

class ProductVariantDTO
{
    public int $id;
    public int $stockQuantity;
    public ?ColorDTO $color = null;
    public ?SizeDTO $size = null;

    public function __construct(int $id, int $stockQuantity, ?ColorDTO $color, ?SizeDTO $size)
    {
        $this->id = $id;
        $this->stockQuantity = $stockQuantity;
        $this->color = $color;
        $this->size = $size;
    }

    public static function fromEntity($variant): self
    {
        return new self(
            $variant->getId(),
            $variant->getStockQuantity(),
            $variant->getColor() ? ColorDTO::fromEntity($variant->getColor()) : null,
            $variant->getSize() ? SizeDTO::fromEntity($variant->getSize()) : null
        );
    }
}
