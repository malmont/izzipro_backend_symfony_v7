<?php
namespace App\Dto;

use App\Entity\BaniereStatique;

class BaniereStatiqueOutputDto
{
    public int $id;
    public string $titre;
    public ?string $texte;
    public ?string $imageDeFondUrl;
    public ?string $texteBouton;
    public ?string $colorBackground; 

    public function __construct(BaniereStatique $entity, string $baseImageUrl, string $locale)
    {
        $translation = $entity->getTranslation($locale);
        $this->id = $entity->getId();
        $this->titre = $translation?->getTitre() ?? $entity->getTitre();
        $this->texte = $translation?->getTexte() ?? $entity->getTexte();
        $this->texteBouton = $translation?->getTexteBouton() ?? $entity->getTexteBouton();
         $this->imageDeFondUrl = $entity->getImageDeFond() ? $baseImageUrl . '/' . $entity->getImageDeFond() : null;
        $this->colorBackground = $entity->getColorBackground(); 
    }
}