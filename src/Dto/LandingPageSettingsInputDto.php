<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class LandingPageSettingsInputDto
{
    /**
     * @var array
     * @Assert\NotBlank
     * @Assert\Type("array")
     */
    public array $configuration;

    public function __construct(array $configuration = [])
    {
        $this->configuration = $configuration;
    }
}
