<?php

namespace App\Dto;

class AddressSuggestionResultDto
{
    public string $description;
    public string $placeId;

    public function __construct(string $description, string $placeId)
    {
        $this->description = $description;
        $this->placeId     = $placeId;
    }
}
