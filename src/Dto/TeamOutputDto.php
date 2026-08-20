<?php

namespace App\Dto;

use App\Entity\Team;

class TeamOutputDto
{
    public int $id;
    public ?string $name;
    public ?string $role;
    public ?string $description;
    public ?string $imageUrl;

    public static function fromEntity(Team $team, string $host, string $locale): self
    {
        $dto = new self();
        $translation = $team->getTranslation($locale);

        $dto->id = $team->getId();
        $dto->name = $team->getName();
        $dto->role = $translation?->getRole() ?? $team->getRole();
        $dto->description = $translation?->getDescription() ?? $team->getDescription();

        $path = $team->getImage();
        if (str_starts_with((string)$path, 'http')) {
            $dto->imageUrl = $path;
        } elseif ($path) {
            $cleanedHost = rtrim($host, '/');
            $dto->imageUrl = $cleanedHost . '/assets/uploads/team/' . $path;
        } else {
            $dto->imageUrl = null;
        }

        return $dto;
    }
}
