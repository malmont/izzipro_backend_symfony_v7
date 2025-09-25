<?php

namespace App\Dto;

use App\Entity\CategorieMarque;

class CategorieMarqueOutputDto
{
    public int $id;
    public ?string $nom;
    public int $nombreMarques;
    /**
     * @var MarqueOutputDto[]
     */
    public array $marques; 
    
    public function __construct(CategorieMarque $categorieMarque, ?string $baseImageUrl = null, ?string $locale = 'fr')
    {

        $translation = $categorieMarque->getTranslation($locale);
        $this->nom = $translation ? $translation->getNom() : $categorieMarque->getNom();

        $this->id = $categorieMarque->getId();
        $marquesCollection = $categorieMarque->getMarques();
        $this->nombreMarques = $marquesCollection->count();
        $this->marques = [];
        foreach ($marquesCollection as $marque) {
            $this->marques[] = new MarqueOutputDto($marque, $baseImageUrl);
        }
    }
}