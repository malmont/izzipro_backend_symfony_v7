<?php
namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class EmploiInputDto
{
    #[Assert\NotBlank(message: "Le titre ne peut pas être vide.")]
    #[Assert\Length(min: 3, max: 255)]
    public ?string $titre = null;

    #[Assert\Length(max: 5000)]
    public ?string $description = null;
}