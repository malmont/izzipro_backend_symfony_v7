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

        $imagePath = $category->getImage();
        if (empty($imagePath)) {
            $this->image = null;
        } elseif (str_starts_with($imagePath, 'http://') || str_starts_with($imagePath, 'https://')) {
            $this->image = $imagePath;
        } else {
            $cleanedHost = rtrim($host, '/');
            $this->image = $cleanedHost . '/assets/uploads/categories/' . $imagePath;
        }
        

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