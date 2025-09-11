<?php
namespace App\Dto;

use App\Entity\ServiceOffer;

class ServiceOfferOutputDto
{
    public int $id;
    public ?string $titre;
    public ?string $logoUrl;
    public ?string $photoServiceUrl;
    public ?string $titreCommentaire;
    public ?string $descriptions;

    public function __construct(ServiceOffer $serviceOffer, string $baseImageUrl, string $locale)
    {
        // $translation = $serviceOffer->getTranslation($locale);
        // TODO: Après la migration, on branchera la logique de traduction ici.

        $this->id = $serviceOffer->getId();
        $this->titre = $serviceOffer->getTitre();
        $this->titreCommentaire = $serviceOffer->getTitreCommentaire();
        $this->descriptions = $serviceOffer->getDescriptions();

        $this->logoUrl = $serviceOffer->getLogo()
            ? rtrim($baseImageUrl, '/') . '/' . $serviceOffer->getLogo()
            : null;
        $this->photoServiceUrl = $serviceOffer->getPhotoService()
            ? rtrim($baseImageUrl, '/') . '/' . $serviceOffer->getPhotoService()
            : null;
    }
}