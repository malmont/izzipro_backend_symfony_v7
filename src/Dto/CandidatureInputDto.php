<?php
namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class CandidatureInputDto
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 255)]
    public ?string $nomComplet = null;

    #[Assert\NotBlank]
    #[Assert\Email]
    public ?string $courriel = null;

    #[Assert\NotBlank(message: "Veuillez sélectionner une offre d'emploi.")]
    public ?int $emploiId = null;

    public ?string $tel = null;
    public ?string $adresse = null;
    public ?string $ville = null;
    public ?string $codePostal = null;
    public ?string $anneeExperience = null;
    public ?string $dateDisponibilite = null; // On attend une date au format Y-m-d
    public ?string $questionCommentaire = null;
    public ?string $niveauAnglais = null;
    public ?string $succursale = null;
    public ?string $lienCv = null;
}

