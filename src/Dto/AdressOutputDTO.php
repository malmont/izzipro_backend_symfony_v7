<?php
namespace App\Dto;

use App\Entity\Adress;

class AdressOutputDTO
{
    public int $id;
    public string $firstname;
    public string $lastname;
    public string $fullname;
    public ?string $company;
    public string $addressLineOne;
    public ?string $addressLineTwo;
    public string $city;
    public string $zipCode;
    public string $country;
    public ?string $province;
    public string $contactNumber;
    public bool $isPrimary;


    public function __construct(Adress $adress)
    {
        $this->id = $adress->getId();
        $this->firstname = $adress->getFirstname();
        $this->lastname = $adress->getLastname();
        $this->fullname = $adress->getFullname();
        $this->company = $adress->getCompany();
        $this->addressLineOne = $adress->getAddress();
        $this->addressLineTwo = $adress->getComplement();
        $this->city = $adress->getCity();
        $this->zipCode = $adress->getCodepostal();
        $this->province = $adress->getProvince();
        $this->country = $adress->getCountry();
        $this->contactNumber = $adress->getPhone();
        $this->isPrimary = $adress->getUserAdress() ? $adress->getUserAdress()->getPrimaryAddress() === $adress : false;
    }
}
