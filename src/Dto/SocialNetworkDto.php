<?php

namespace App\Dto;

use App\Entity\SocialNetwork;

class SocialNetworkDto
{
    public ?string $name = null;
    public ?string $url = null;

    public static function fromEntity(SocialNetwork $socialNetwork): self
    {
        $dto = new self();
        $dto->name = $socialNetwork->getName();
        $dto->url = $socialNetwork->getUrl();

        return $dto;
    }
}
