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
        // --- Logique "en attente" ---
        // $translation = $entity->getTranslation($locale);
        // TODO: Après la migration, on branchera la logique de traduction ici.

        $this->id = $entity->getId();
        $this->titre = $entity->getTitre();
        $this->texte = $entity->getTexte();
        $this->image = $entity->getImage() ? rtrim($baseImageUrl, '/') . '/' . $entity->getImage() : null;
        $this->texteBouton = $entity->getTexteBouton();
        $this->lienBouton = $entity->getLienBouton();
    }
}