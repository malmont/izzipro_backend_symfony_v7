<?php
namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Doctrine\DBAL\Types\Types;

class BaniereStatiqueInputDto
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 255)]
    public ?string $titre = null;

    #[Assert\Type(Types::TEXT)]
    public ?string $texte = null;

    #[Assert\Length(max: 255)]
    public ?string $imageDeFond = null;

    #[Assert\Length(max: 255)]
    public ?string $texteBouton = null;

    #[Assert\Length(max: 255)]
    public ?string $colorBackground = null;
}
