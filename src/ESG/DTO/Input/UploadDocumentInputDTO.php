<?php

namespace App\ESG\DTO\Input;

use App\ESG\Enum\DomainEnum;
use Symfony\Component\Validator\Constraints as Assert;

class UploadDocumentInputDTO
{
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^(ENV|GOV|SOC|CLI)-\d{3}$/')]
    public string $code;

    #[Assert\NotBlank]
    #[Assert\Choice(callback: [DomainEnum::class, 'values'])]
    public string $domain;
}
