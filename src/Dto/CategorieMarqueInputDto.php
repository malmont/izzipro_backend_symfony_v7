<?php
namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class CategorieMarqueInputDto
{
    #[Assert\NotBlank(message: "Le nom ne peut pas être vide.")]
    #[Assert\Length(min: 2, max: 255)]
    public ?string $nom = null;
}