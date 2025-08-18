<?php
namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class EmbedInputDto
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 255)]
    public ?string $titre = null;

    #[Assert\NotBlank]
    #[Assert\Url]
    public ?string $embedUrl = null;
}
