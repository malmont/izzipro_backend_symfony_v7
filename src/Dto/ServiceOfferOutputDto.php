<?php
namespace App\Dto;

use App\Entity\ServiceOffer;

class ServiceOfferOutputDto
{
    public int $id;
    public string $titre;
    public ?string $logoUrl;
    public ?string $titreCommentaire;
    public ?string $descriptions;

    public function __construct(ServiceOffer $serviceOffer, string $baseImageUrl)
    {
        $this->id = $serviceOffer->getId();
        $this->titre = $serviceOffer->getTitre();
        $this->logoUrl = $serviceOffer->getLogo()
            ? rtrim($baseImageUrl, '/') . '/' . $serviceOffer->getLogo()
            : null;
        $this->titreCommentaire = $serviceOffer->getTitreCommentaire();
        $this->descriptions = $serviceOffer->getDescriptions();
    }
}