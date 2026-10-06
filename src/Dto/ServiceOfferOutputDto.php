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

        $this->logoUrl = \App\Services\MediaUrlResolver::joinStored($serviceOffer->getLogo(), $baseImageUrl);
        $this->photoServiceUrl = \App\Services\MediaUrlResolver::joinStored($serviceOffer->getPhotoService(), $baseImageUrl);
    }
}