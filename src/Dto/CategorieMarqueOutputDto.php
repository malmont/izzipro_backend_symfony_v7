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

        // $translation = $categorieMarque->getTranslation($locale);
        // TODO: Après la migration, on "branchera" la logique de traduction ici.
        // $this->nom = $translation ? $translation->getNom() : $categorieMarque->getNom();

        $this->id = $categorieMarque->getId();
        $this->nom = $categorieMarque->getNom(); // Utilise l'ancienne méthode
        
        $marquesCollection = $categorieMarque->getMarques();
        $this->nombreMarques = $marquesCollection->count();
        $this->marques = [];
        foreach ($marquesCollection as $marque) {
            $this->marques[] = new MarqueOutputDto($marque, $baseImageUrl);
        }
    }
}