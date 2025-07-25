<?php
namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class ServiceOfferInputDto
{
    #[Assert\NotBlank(message: "Le titre ne peut pas être vide.")]
    #[Assert\Length(min: 3, max: 255)]
    public ?string $titre = null;

    public ?string $logo = null;

    #[Assert\Length(max: 255)]
    public ?string $titreCommentaire = null;

    #[Assert\Length(max: 255)]
    public ?string $descriptions = null;
}
