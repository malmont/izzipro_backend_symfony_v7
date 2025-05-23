<?php

namespace App\Dto;

class AddressDetailsResultDto
{
    public string $street1;
    public ?string $street2;
    public string $city;
    public string $province;
    public string $postalCode;
    public string $country;

    public function __construct(array $data)
    {
        $this->street1    = $data['street1'];
        $this->street2    = $data['street2']    ?? null;
        $this->city       = $data['city'];
        $this->province   = $data['province'];
        $this->postalCode = $data['postal_code'];
        $this->country    = $data['country'];
    }
}
