<?php

namespace App\ESG\DTO\Output;

use App\ESG\Entity\EsgCompany;

class CompanyOutputDTO
{
    public int $id;
    public string $name;
    public string $slug;
    public string $sector;
    public string $sizeCategory;
    public string $territory;
    public ?string $city;
    public ?string $website;
    public array $existingCertifications;
    public string $contactEmail;
    public ?string $contactPhone;

    public function __construct(EsgCompany $company)
    {
        $this->id = $company->getId();
        $this->name = $company->getName();
        $this->slug = $company->getSlug();
        $this->sector = $company->getSector()->value;
        $this->sizeCategory = $company->getSizeCategory()->value;
        $this->territory = $company->getTerritory()->value;
        $this->city = $company->getCity();
        $this->website = $company->getWebsite();
        $this->existingCertifications = $company->getExistingCertifications();
        $this->contactEmail = $company->getContactEmail();
        $this->contactPhone = $company->getContactPhone();
    }
}
