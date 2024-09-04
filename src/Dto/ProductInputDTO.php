<?php
namespace App\Dto;

class ProductInputDTO
{
    public string $name;
    public string $description;
    public ?float $purchasePrice;
    public ?float $coefficientMultiplier;
    public ?int $styleId;
    public array $categoryIds;  // Type array ici
    public ?string $image;

    public function __construct(array $data)
    {
        $this->name = $data['name'];
        $this->description = $data['description'];
        $this->purchasePrice = $data['purchasePrice'] ?? null;
        $this->coefficientMultiplier = $data['coefficientMultiplier'] ?? null;
        $this->styleId = $data['style_id'] ?? null;

        // S'assurer que categoryIds est bien un tableau
        $categoryIds = $data['category_ids'] ?? [];
        if (is_string($categoryIds)) {
            $categoryIds = explode(',', $categoryIds);  // Si c'est une chaîne, on la convertit en tableau
        }
        $this->categoryIds = (array) $categoryIds;  // On cast pour s'assurer que c'est bien un tableau

        $this->image = $data['image'] ?? null;
    }
}
