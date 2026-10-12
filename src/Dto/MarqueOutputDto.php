<?php
namespace App\Dto;

use App\Entity\Marque;

class MarqueOutputDto
{
    public int $id;
    /** Contenu d'exemple encore à remplacer (copié du site de référence, jamais modifié) */
    public bool $isDemo = false;
    public string $titre;
    public ?string $logosMarquesUrl;
    public array $categories = [];

    public function __construct(Marque $marque, ?string $baseImageUrl = null)
    {
        $this->id = $marque->getId();
        $this->isDemo = $marque->isDemo();
        $this->titre = $marque->getTitre();
        $this->logosMarquesUrl = $marque->getLogosMarques() && $baseImageUrl
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