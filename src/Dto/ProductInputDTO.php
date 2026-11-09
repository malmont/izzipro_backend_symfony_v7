<?php
namespace App\Dto;

class ProductInputDTO
{
    public string $name;
    public string $description;
    public ?float $purchasePrice;
    public ?float $coefficientMultiplier;
    public ?int $quantity = null; // facultative (lot G4, 09/11/2026)
    public ?int $styleId;
    public array $categoryIds;  
    public ?string $image;

    public function __construct(array $data)
    {
        $this->name = $data['name'];
        $this->description = $data['description'];
        $this->purchasePrice = $data['purchasePrice'] ?? null;
        $this->coefficientMultiplier = $data['coefficientMultiplier'] ?? null;
        $this->quantity = isset($data['quantity']) && $data['quantity'] !== '' ? (int) $data['quantity'] : null;
        $this->styleId = $data['style_id'] ?? null;
        $categoryIds = $data['category_ids'] ?? [];
        if (is_string($categoryIds)) {
            $categoryIds = explode(',', $categoryIds);  
        }
        $this->categoryIds = (array) $categoryIds; 

        $this->image = $data['image'] ?? null;
    }
}
