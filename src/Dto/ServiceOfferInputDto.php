<?php
namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class ServiceOfferInputDto
{
    #[Assert\NotBlank(message: "Le titre ne peut pas être vide.")]
    #[Assert\Length(min: 3, max: 255)]
    public ?string $titre = null;

    public ?string $logo = null;

    public ?string $photoService = null;

    #[Assert\Length(max: 255)]
    public ?string $titreCommentaire = null;

    #[Assert\Length(max: 255)]
    public ?string $descriptions = null;

    /** Famille (id de /api/service-families) ; 0 retire la famille ; absent ou null : inchangée (28/10/2026) */
    #[Assert\PositiveOrZero]
    public ?int $familyId = null;

    #[Assert\Range(min: 0, max: 100000)]
    public ?int $position = null;

    public ?bool $bookable = null;

    #[Assert\Range(min: 1, max: 1440)]
    public ?int $durationMinutes = null;
}
