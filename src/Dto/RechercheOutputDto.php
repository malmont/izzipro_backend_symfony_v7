<?php
namespace App\Dto;

use App\Entity\Recherche;

class RechercheOutputDto
{
    public int $id;
    public ?string $titre;
    public ?string $texte1;
    public ?string $texte2;
    public ?string $imageDeFondUrl;

    public function __construct(Recherche $recherche, string $baseImageUrl, string $locale)
    {
        // $translation = $recherche->getTranslation($locale);
        // TODO: Après la migration, on branchera la logique de traduction ici.

        $this->id = $recherche->getId();
        $this->titre = $recherche->getTitre();
        $this->texte1 = $recherche->getTexte1();
        $this->texte2 = $recherche->getTexte2();
        $this->imageDeFondUrl = $recherche->getImageDeFond()
            ? rtrim($baseImageUrl, '/') . '/' . $recherche->getImageDeFond()
            : null;
    }
}