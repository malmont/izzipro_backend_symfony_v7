<?php

namespace App\ESG\DTO\Input;

use Symfony\Component\Validator\Constraints as Assert;

class CompanyProfileInputDTO
{
    #[Assert\NotBlank]
    public string $name;

    #[Assert\NotBlank]
    public string $sector;

    #[Assert\NotBlank]
    public string $sizeCategory;

    #[Assert\NotBlank]
    public string $territory;

    #[Assert\NotNull]
    public array $existingCertifications = [];

    #[Assert\NotBlank]
    #[Assert\Email]
    public string $contactEmail;

    public ?string $city = null;

    #[Assert\Url(message: 'L\'URL du site web n\'est pas valide')]
    public ?string $website = null;
}
