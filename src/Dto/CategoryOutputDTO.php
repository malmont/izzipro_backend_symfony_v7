<?php
namespace App\Dto;

use App\Entity\Categories;

class CategoryOutputDTO
{
    public int $id;
    public ?string $name;
    public ?string $description;
    public ?string $image;

    public function __construct(Categories $category, string $host, string $locale)
    {
        $translation = $category->getTranslation($locale);

        $this->id = $category->getId();
        $this->name = $translation?->getName() ?? $category->getName(); 
        $this->description = $translation?->getDescription() ?? $category->getDescription(); 
        
        $this->image = $category->getImage() 
            ? rtrim($host, '/') . '/assets/uploads/categories/' . $category->getImage() 
            : null;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'image' => $this->image,
        ];
    }
}