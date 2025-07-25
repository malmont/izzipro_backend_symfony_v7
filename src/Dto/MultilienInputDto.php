<?php
namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class MultilienInputDto
{
    #[Assert\NotBlank(message: "Le titre ne peut pas être vide.")]
    #[Assert\Length(min: 3, max: 255)]
    public ?string $titre = null;

    #[Assert\NotBlank(message: "Le lien ne peut pas être vide.")]
    #[Assert\Url]
    public ?string $lien = null;

    public ?string $imageDeFond = null;
}