<?php
namespace App\Dto;

class AdressInputDTO
{
    public string $firstname;
    public string $lastname;
    public ?string $company;
    public string $addressLineOne;
    public ?string $addressLineTwo;
    public string $city;
    public string $zipCode;
    public string $country;
    public string $contactNumber;

    public function __construct(array $data)
    {
        $this->firstname = $data['firstname'] ?? '';
        $this->lastname = $data['lastname'] ?? '';
        $this->company = $data['company'] ?? null;
        $this->addressLineOne = $data['addressLineOne'] ?? '';
        $this->addressLineTwo = $data['addressLineTwo'] ?? null;
        $this->city = $data['city'] ?? '';
        $this->zipCode = (string)($data['zipCode'] ?? 0);
        $this->country = $data['country'] ?? '';
        $this->contactNumber = (string)($data['contactNumber'] ?? 0);
    }
}
