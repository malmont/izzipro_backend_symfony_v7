<?php
namespace App\Dto;

use App\Entity\Banniere;

class BanniereOutputDto
{
    public int $id;
    public ?string $titre; 
    public ?string $texte;
    public ?string $imageDeFondUrl;

    public function __construct(Banniere $banniere, string $baseImageUrl, string $locale)
    {
        $translation = $banniere->getTranslation($locale);
        $this->id = $banniere->getId();
        $this->titre = $banniere->getTitre();
        $this->texte = $banniere->getTexte();
        $this->imageDeFondUrl = $banniere->getImageDeFond()
            ? rtrim($baseImageUrl, '/') . '/' . $banniere->getImageDeFond()
            : null;
    }
}