<?php

namespace App\ESG\DTO\Input;

use Symfony\Component\Validator\Constraints as Assert;

class RegisterInputDTO
{
    #[Assert\NotBlank]
    #[Assert\Email]
    public string $email;

    #[Assert\NotBlank]
    #[Assert\Length(min: 8, minMessage: 'Le mot de passe doit faire au moins 8 caractères')]
    public string $password;

    #[Assert\NotBlank]
    public string $firstName;

    #[Assert\NotBlank]
    public string $lastName;

    #[Assert\NotBlank]
    public string $companyName;

    #[Assert\NotBlank]
    public string $sector;

    #[Assert\NotBlank]
    public string $sizeCategory;

    #[Assert\NotBlank]
    public string $territory;

    #[Assert\NotBlank]
    #[Assert\Email]
    public string $contactEmail;
}
