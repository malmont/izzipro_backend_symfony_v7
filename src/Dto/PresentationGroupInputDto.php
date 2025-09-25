<?php
namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class PresentationGroupInputDto
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 255)]
    public ?string $titre = null;

    /**
     * @var int[]
     */
    #[Assert\All([
        new Assert\Type('integer')
    ])]
    public array $presentations = [];
}