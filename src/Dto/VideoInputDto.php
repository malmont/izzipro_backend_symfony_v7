<?php
namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class VideoInputDto
{
    #[Assert\NotBlank(message: "Le titre ne peut pas être vide.")]
    #[Assert\Length(min: 3, max: 255)]
    public ?string $titre = null;

    #[Assert\Url(message: "Le lien vidéo doit être une URL valide.")]
    public ?string $lienVideo = null;

    public ?string $imageDeFond = null;
}