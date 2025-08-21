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
    public ?string $colorBackground; // NOUVEAU

    public function __construct(BaniereStatique $entity, string $baseImageUrl)
    {
        $this->id = $entity->getId();
        $this->titre = $entity->getTitre();
        $this->texte = $entity->getTexte();
        $this->imageDeFondUrl = $entity->getImageDeFond() ? $baseImageUrl . '/' . $entity->getImageDeFond() : null;
        $this->texteBouton = $entity->getTexteBouton();
        $this->colorBackground = $entity->getColorBackground(); // NOUVEAU
    }
}