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
    public int $zipCode;
    public string $country;
    public int $contactNumber;

    public function __construct(array $data)
    {
        $this->firstname = $data['firstname'] ?? '';
        $this->lastname = $data['lastname'] ?? '';
        $this->company = $data['company'] ?? null;
        $this->addressLineOne = $data['addressLineOne'] ?? '';
        $this->addressLineTwo = $data['addressLineTwo'] ?? null;
        $this->city = $data['city'] ?? '';
        $this->zipCode = (int)($data['zipCode'] ?? 0);
        $this->country = $data['country'] ?? '';
        $this->contactNumber = (int)($data['contactNumber'] ?? 0);
    }
}
