<?php
namespace App\Dto;

use App\Entity\Categories;

class CategoryOutputDTO
{
    public int $id;
    public string $name;
    public ?string $description;
    public ?string $image;

    public function __construct(Categories $category, string $host)
    {
        $this->id = $category->getId();
        $this->name = $category->getName();
        $this->description = $category->getDescription();
        $this->image = $category->getImage() ? $host . '/assets/uploads/categories/' . $category->getImage() : null;
    }
}
