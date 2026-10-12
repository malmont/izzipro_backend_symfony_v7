<?php
namespace App\Dto;

use App\Entity\Banniere;

class BanniereOutputDto
{
    public int $id;
    /** Contenu d'exemple encore à remplacer (copié du site de référence, jamais modifié) */
    public bool $isDemo = false;
    public ?string $titre; 
    public ?string $texte;
    public ?string $imageDeFondUrl;

    public function __construct(Banniere $banniere, string $baseImageUrl, string $locale)
    {
        $translation = $banniere->getTranslation($locale);
        $this->id = $banniere->getId();
        $this->isDemo = $banniere->isDemo();
        $this->titre = $translation?->getTitre() ?? $banniere->getTitre();
        $this->texte = $translation?->getTexte() ?? $banniere->getTexte();
        $this->imageDeFondUrl = \App\Services\MediaUrlResolver::joinStored($banniere->getImageDeFond(), $baseImageUrl);
    }
}