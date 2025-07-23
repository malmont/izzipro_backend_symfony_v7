<?php
// src/Dto/AdressInputDTO.php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class AdressInputDTO
{
    #[Assert\NotBlank(message: "Le prénom est obligatoire.")]
    public string $firstname;

    #[Assert\NotBlank(message: "Le nom de famille est obligatoire.")]
    public string $lastname;

    public ?string $company = null;

    #[Assert\NotBlank(message: "La première ligne d’adresse est obligatoire.")]
    public string $addressLineOne;

    public ?string $addressLineTwo = null;

    #[Assert\NotBlank(message: "La ville est obligatoire.")]
    public string $city;

    #[Assert\NotBlank(message: "La province / région est obligatoire.")]
    public string $province;

    #[Assert\NotBlank(message: "Le code postal est obligatoire.")]
    #[Assert\Regex(
        pattern: '/^[A-Za-z]\d[A-Za-z][ -]?\d[A-Za-z]\d$/',
        message: "Le code postal doit être au format canadien A1A 1A1."
    )]
    public string $zipCode;

    #[Assert\NotBlank(message: "Le pays est obligatoire.")]
    public string $country;

    #[Assert\NotBlank(message: "Le numéro de contact est obligatoire.")]
    #[Assert\Regex(
        pattern: '/^\+?[0-9\-\s]{7,20}$/',
        message: "Le numéro de contact doit contenir entre 7 et 20 chiffres et peut comporter +, espaces ou tirets."
    )]
    public string $contactNumber;

    // --- NOUVELLE PROPRIÉTÉ ---
    public bool $isPrimary = false;

    public function __construct(array $data)
    {
        $this->firstname       = $data['firstname']       ?? '';
        $this->lastname        = $data['lastname']        ?? '';
        $this->company         = $data['company']         ?? null;
        $this->addressLineOne  = $data['addressLineOne']  ?? '';
        $this->addressLineTwo  = $data['addressLineTwo']  ?? null;
        $this->city            = $data['city']            ?? '';
        $this->province        = $data['province']        ?? '';  
        $this->zipCode         = (string) ($data['zipCode']         ?? '');
        $this->country         = $data['country']         ?? '';
        $this->contactNumber   = (string) ($data['contactNumber']   ?? '');
        $this->isPrimary       = (bool) ($data['isPrimary']       ?? false); 
    }
}
