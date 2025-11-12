<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class NewsletterInputDto
{
    #[Assert\NotBlank(message: "L'adresse e-mail ne peut pas être vide.")]
    #[Assert\Email(message: "L'adresse e-mail '{{ value }}' n'est pas valide.")]
    public ?string $email = null;
}