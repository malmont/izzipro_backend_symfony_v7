<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class TenantSetupDTO
{
    #[Assert\NotBlank]
    #[Assert\Regex('/^[a-z0-9_]+$/i', message: "L'identifiant ne doit contenir que des lettres, chiffres ou underscores.")]
    public ?string $code = null;

    #[Assert\NotBlank]
    #[Assert\Regex('/^[a-z0-9-]+$/i', message: "Le sous-domaine ne doit contenir que des lettres, chiffres ou tirets.")]
    public ?string $subdomain = null;

    #[Assert\NotBlank(message: "La clé de création est obligatoire.")]
    public ?string $secretKey = null;

    #[Assert\NotBlank(message: "Le nom de l'entreprise est obligatoire.")]
    public ?string $companyName = null;

    #[Assert\NotBlank(message: "L'email de l'entreprise est obligatoire.")]
    #[Assert\Email(message: "L'adresse email n'est pas valide.")]
    public ?string $companyEmail = null;

    public ?string $companyPhone = null;
    public ?string $companyWebsite = null;
    public ?string $companyEin = null;
    public ?string $companyTva = null;

    #[Assert\NotBlank(message: "L'adresse est obligatoire.")]
    public ?string $street1 = null;
    public ?string $street2 = null;

    #[Assert\NotBlank(message: "La ville est obligatoire.")]
    public ?string $city = null;

    #[Assert\NotBlank(message: "Le code postal est obligatoire.")]
    public ?string $zip = null;

    public ?string $state = null;

    #[Assert\NotBlank(message: "Le pays est obligatoire.")]
    public ?string $country = 'FR';
}