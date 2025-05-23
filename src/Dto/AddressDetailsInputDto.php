<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class AddressDetailsInputDto
{
    #[Assert\NotBlank(message: "Le place_id est obligatoire.")]
    public string $placeId;

    public function __construct(array $data)
    {
        $this->placeId = $data['place_id'] ?? '';
    }
}
