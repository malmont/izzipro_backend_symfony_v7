<?php
namespace App\Dto;

use App\Entity\ServiceOffer;

class ServiceOfferOutputDto
{
    public int $id;
    /** Contenu d'exemple encore à remplacer (copié du site de référence, jamais modifié) */
    public bool $isDemo = false;
    public ?string $titre;
    public ?string $logoUrl;
    public ?string $photoServiceUrl;
    public ?string $titreCommentaire;
    public ?string $descriptions;
    /** Famille, ordre, réservable, durée (28/10/2026) */
    public ?int $familyId = null;
    public ?array $family = null;
    public int $position = 0;
    public bool $bookable = true;
    public ?int $durationMinutes = null;

    public function __construct(ServiceOffer $serviceOffer, string $baseImageUrl, string $locale)
    {
        // $translation = $serviceOffer->getTranslation($locale);
        // TODO: Après la migration, on branchera la logique de traduction ici.

        $this->id = $serviceOffer->getId();

        $this->isDemo = $serviceOffer->isDemo();
        $this->titre = $serviceOffer->getTitre();
        $this->titreCommentaire = $serviceOffer->getTitreCommentaire();
        $this->descriptions = $serviceOffer->getDescriptions();
        $family = $serviceOffer->getFamily();
        $this->familyId = $family?->getId();
        $this->family = $family ? ['id' => (int) $family->getId(), 'name' => $family->nameFor($locale), 'position' => $family->getPosition()] : null;
        $this->position = $serviceOffer->getPosition();
        $this->bookable = $serviceOffer->isBookable();
        $this->durationMinutes = $serviceOffer->getDurationMinutes();

        $this->logoUrl = \App\Services\MediaUrlResolver::joinStored($serviceOffer->getLogo(), $baseImageUrl);
        $this->photoServiceUrl = \App\Services\MediaUrlResolver::joinStored($serviceOffer->getPhotoService(), $baseImageUrl);
    }
}