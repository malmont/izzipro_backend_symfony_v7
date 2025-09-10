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

        // $translation = $category->getTranslation($locale);
        // TODO: Après la migration, on "branchera" la logique de traduction ici.

        $this->id = $category->getId();
        $this->name = $category->getName(); 
        $this->description = $category->getDescription(); 
        $this->image = $category->getImage() ? rtrim($host, '/') . '/assets/uploads/categories/' . $category->getImage() : null;
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