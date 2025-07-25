<?php
namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class RechercheInputDto
{
    #[Assert\NotBlank(message: "Le titre ne peut pas être vide.")]
    #[Assert\Length(min: 3, max: 255)]
    public ?string $titre = null;

    #[Assert\Length(max: 255)]
    public ?string $texte1 = null;

    #[Assert\Length(max: 255)]
    public ?string $texte2 = null;

    public ?string $imageDeFond = null;
}
