<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class TenantSetupDTO
{
    #[Assert\NotBlank(message: "L'identifiant est obligatoire.")]
    #[Assert\Regex('/^[a-z0-9_-]+$/i', message: "L'identifiant ne doit contenir que des lettres, chiffres, tirets ou underscores.")]
    public ?string $code = null;

    #[Assert\NotBlank(message: "Le sous-domaine est obligatoire.")]
    #[Assert\Regex('/^[a-z0-9_-]+$/i', message: "Le sous-domaine ne doit contenir que des lettres, chiffres, tirets ou underscores.")]
    public ?string $subdomain = null;

    #[Assert\NotBlank(message: "La clé de création est obligatoire.")]
    public ?string $secretKey = null;

    #[Assert\NotBlank(message: "Le nom de l'entreprise est obligatoire.")]
    #[Assert\Length(max: 180, maxMessage: "Le nom de l'entreprise ne doit pas dépasser 180 caractères.")]
    public ?string $companyName = null;

    #[Assert\NotBlank(message: "L'email de l'entreprise est obligatoire.")]
    #[Assert\Email(message: "L'adresse email n'est pas valide.")]
    #[Assert\Length(max: 180, maxMessage: "L'adresse email ne doit pas dépasser 180 caractères.")]
    public ?string $companyEmail = null;

    #[Assert\Length(max: 30, maxMessage: "Le numéro de téléphone ne doit pas dépasser 30 caractères.")]
    public ?string $companyPhone = null;

    #[Assert\Length(max: 255, maxMessage: "L'adresse du site web ne doit pas dépasser 255 caractères.")]
    public ?string $companyWebsite = null;

    #[Assert\Length(max: 50, maxMessage: "L'identifiant SIRET/EIN ne doit pas dépasser 50 caractères.")]
    public ?string $companyEin = null;

    #[Assert\Length(max: 50, maxMessage: "Le numéro de TVA ne doit pas dépasser 50 caractères.")]
    public ?string $companyTva = null;

    #[Assert\NotBlank(message: "L'adresse est obligatoire.")]
    #[Assert\Length(max: 255, maxMessage: "L'adresse ne doit pas dépasser 255 caractères.")]
    public ?string $street1 = null;

    #[Assert\Length(max: 255, maxMessage: "Le complément d'adresse ne doit pas dépasser 255 caractères.")]
    public ?string $street2 = null;

    #[Assert\NotBlank(message: "La ville est obligatoire.")]
    #[Assert\Length(max: 100, maxMessage: "La ville ne doit pas dépasser 100 caractères.")]
    public ?string $city = null;

    #[Assert\NotBlank(message: "Le code postal est obligatoire.")]
    #[Assert\Length(max: 20, maxMessage: "Le code postal ne doit pas dépasser 20 caractères.")]
    public ?string $zip = null;

    #[Assert\Length(max: 100, maxMessage: "La région/état ne doit pas dépasser 100 caractères.")]
    public ?string $state = null;

    #[Assert\NotBlank(message: "Le pays est obligatoire.")]
    #[Assert\Length(max: 50, maxMessage: "Le pays ne doit pas dépasser 50 caractères.")]
    public ?string $country = 'FR';
}