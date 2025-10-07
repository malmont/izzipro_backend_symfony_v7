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

    // #[Assert\NotBlank]
    // public ?string $companyName = null;

    // #[Assert\Email]
    // public ?string $companyEmail = null;
    
    // public ?string $companyTva = null;
    // public ?string $companyEin = null;

    // // Pour l'upload de fichier
    // #[Assert\Image(maxSize: '1024k')]
    // public ?UploadedFile $companyLogo = null;
    
    // #[Assert\NotBlank]
    // public ?string $adminName = null;
    
    // #[Assert\NotBlank]
    // #[Assert\Email]
    // public ?string $adminEmail = null;

    // #[Assert\NotBlank]
    // #[Assert\Length(min: 8)]
    // public ?string $plainPassword = null;
    
    // Optionnel pour la phase de test
    public ?string $gemsuiteToken = null;
}