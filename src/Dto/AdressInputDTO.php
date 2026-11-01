<?php
// src/Dto/AdressInputDTO.php

namespace App\Dto;

use App\Services\AdressService\PostalCodeRules;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

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

    // Format selon le pays (01/11/2026, PostalCodeRules) : canadien A1A 1A1 inchangé, américain 12345(-6789),
    // autres pays 3 à 10 caractères ; avant, le format canadien était exigé quel que soit le pays
    #[Assert\NotBlank(message: "Le code postal est obligatoire.")]
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

    #[Assert\Callback]
    public function validateZipCode(ExecutionContextInterface $context): void
    {
        if ($this->zipCode === '') {
            return; // NotBlank s'en charge
        }
        $message = PostalCodeRules::violation($this->zipCode, $this->country);
        if ($message !== null) {
            $context->buildViolation($message)->atPath('zipCode')->addViolation();
        }
    }

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
