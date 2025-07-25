<?php
namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class BanniereInputDto
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 255)]
    public ?string $titre = null;

    #[Assert\Length(max: 1000)]
    public ?string $texte = null;

}