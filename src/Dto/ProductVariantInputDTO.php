<?php
namespace App\Dto;

class ProductVariantInputDTO
{
    public int $stockQuantity;
    public ?int $colorId = null;
    public ?int $sizeId = null;

    public function __construct(int $stockQuantity, ?int $colorId = null, ?int $sizeId = null)
    {
        $this->stockQuantity = $stockQuantity;
        $this->colorId = $colorId;
        $this->sizeId = $sizeId;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['stockQuantity'],
            $data['color']['id'] ?? null,
            $data['size']['id'] ?? null
        );
    }
}
