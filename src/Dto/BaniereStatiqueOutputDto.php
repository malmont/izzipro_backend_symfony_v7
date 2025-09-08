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
        $translation = $entity->getTranslation($locale);// TODO: Après la migration, on "branchera" la logique de traduction ici.
        $this->id = $entity->getId();
        $this->titre = $entity->getTitre();
        $this->texte = $entity->getTexte();
        $this->imageDeFondUrl = $entity->getImageDeFond() ? $baseImageUrl . '/' . $entity->getImageDeFond() : null;
        $this->texteBouton = $entity->getTexteBouton();
        $this->colorBackground = $entity->getColorBackground(); 
    }
}