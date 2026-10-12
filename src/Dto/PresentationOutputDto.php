<?php
namespace App\Dto;

use App\Entity\Presentation;

class PresentationOutputDto
{
    public int $id;
    /** Contenu d'exemple encore à remplacer (copié du site de référence, jamais modifié) */
    public bool $isDemo = false;
    public ?string $titre;
    public ?string $texte;
    public ?string $image;
    public ?string $texteBouton;
    public ?string $lienBouton;

    public function __construct(Presentation $entity, string $baseImageUrl, string $locale)
    {
        $translation = $entity->getTranslation($locale);

        $this->id = $entity->getId();

        $this->isDemo = $entity->isDemo();
        $this->titre = $translation?->getTitre() ?? $entity->getTitre();
        $this->texte = $translation?->getTexte() ?? $entity->getTexte();
        $this->texteBouton = $translation?->getTexteBouton() ?? $entity->getTexteBouton();

        $this->image = \App\Services\MediaUrlResolver::joinStored($entity->getImage(), $baseImageUrl);
        $this->lienBouton = $entity->getLienBouton();
    }
}