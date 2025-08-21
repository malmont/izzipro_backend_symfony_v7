<?php
namespace App\Dto;

use App\Entity\Presentation;

class PresentationOutputDto
{
    public int $id;
    public string $titre;
    public ?string $texte;
    public ?string $image;
    public ?string $texteBouton;
    public ?string $lienBouton;

    public function __construct(Presentation $entity, string $baseImageUrl)
    {
        $this->id = $entity->getId();
        $this->titre = $entity->getTitre();
        $this->texte = $entity->getTexte();
        $this->image = $entity->getImage() ? $baseImageUrl . '/' . $entity->getImage() : null;
        $this->texteBouton = $entity->getTexteBouton();
        $this->lienBouton = $entity->getLienBouton();
    }
}