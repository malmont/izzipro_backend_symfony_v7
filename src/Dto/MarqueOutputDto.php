<?php
namespace App\Dto;

use App\Entity\Marque;

class MarqueOutputDto
{
    public int $id;
    public string $titre;
    public ?string $logosMarquesUrl;
    public array $categories = [];

    public function __construct(Marque $marque, string $baseImageUrl)
    {
        $this->id = $marque->getId();
        $this->titre = $marque->getTitre();
        $this->logosMarquesUrl = $marque->getLogosMarques()
            ? rtrim($baseImageUrl, '/') . '/' . $marque->getLogosMarques()
            : null;

        foreach ($marque->getCategories() as $category) {
            $this->categories[] = [
                'id' => $category->getId(),
                'nom' => $category->getNom(),
            ];
        }
    }
}