<?php
namespace App\Dto;

use App\Entity\BaniereStatique;

class BaniereStatiqueOutputDto
{
    public int $id;
    /** Contenu d'exemple encore à remplacer (copié du site de référence, jamais modifié) */
    public bool $isDemo = false;
    public string $titre;
    public ?string $texte;
    public ?string $imageDeFondUrl;
    public ?string $texteBouton;
    public ?string $colorBackground; 

    public function __construct(BaniereStatique $entity, string $baseImageUrl, string $locale)
    {
        $translation = $entity->getTranslation($locale);
        $this->id = $entity->getId();
        $this->isDemo = $entity->isDemo();
        $this->titre = $translation?->getTitre() ?? $entity->getTitre();
        $this->texte = $translation?->getTexte() ?? $entity->getTexte();
        $this->texteBouton = $translation?->getTexteBouton() ?? $entity->getTexteBouton();
         $this->imageDeFondUrl = \App\Services\MediaUrlResolver::joinStored($entity->getImageDeFond(), $baseImageUrl);
        $this->colorBackground = $entity->getColorBackground(); 
    }
}