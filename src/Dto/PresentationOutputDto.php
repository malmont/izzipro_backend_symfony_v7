<?php
namespace App\Dto;

use App\Entity\Presentation;

class PresentationOutputDto
{
    public int $id;
    public ?string $titre;
    public ?string $texte;
    public ?string $image;
    public ?string $texteBouton;
    public ?string $lienBouton;

    public function __construct(Presentation $entity, string $baseImageUrl, string $locale)
    {
        $translation = $entity->getTranslation($locale);

        $this->id = $entity->getId();
        $this->titre = $translation?->getTitre() ?? $entity->getTitre();
        $this->texte = $translation?->getTexte() ?? $entity->getTexte();
        $this->texteBouton = $translation?->getTexteBouton() ?? $entity->getTexteBouton();

        $this->image = $entity->getImage() ? rtrim($baseImageUrl, '/') . '/' . $entity->getImage() : null;
        $this->lienBouton = $entity->getLienBouton();
    }
}