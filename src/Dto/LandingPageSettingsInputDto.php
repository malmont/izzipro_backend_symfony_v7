<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class LandingPageSettingsInputDto
{
    /**
     * Montée Symfony 7.4 (20/11/2026) : contraintes en attributs. Écrites en annotations, plus rien ne les lisait :
     * un PUT « {} » passait et vidait la landing du site.
     */
    #[Assert\NotBlank]
    #[Assert\Type('array')]
    public array $configuration;

    public function __construct(array $configuration = [])
    {
        $this->configuration = $configuration;
    }
}
