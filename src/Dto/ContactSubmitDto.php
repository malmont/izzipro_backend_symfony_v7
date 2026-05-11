<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class ContactSubmitDto
{
    #[Assert\NotBlank(message: "Champ name manquant.")]
    public ?string $name = null;

    #[Assert\NotBlank(message: "Champ email manquant.")]
    #[Assert\Email(message: "L'email n'est pas valide.")]
    public ?string $email = null;

    #[Assert\NotBlank(message: "Champ phone manquant.")]
    public ?string $phone = null;

    #[Assert\NotBlank(message: "Champ service manquant.")]
    public ?string $service = null;

    #[Assert\NotBlank(message: "Champ message manquant.")]
    public ?string $message = null;
}
