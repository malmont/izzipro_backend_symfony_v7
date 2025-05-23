<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class AddressSuggestionInputDto
{
    #[Assert\NotBlank(message: "Le terme de recherche est obligatoire.")]
    public string $query;

    public function __construct(array $data)
    {
        $this->query = $data['q'] ?? '';
    }
}
