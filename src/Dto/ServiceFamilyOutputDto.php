<?php

namespace App\Dto;

use App\Entity\ServiceFamily;

final class ServiceFamilyOutputDto
{
    /** @return array{id: int, name: string, position: int, translations: list<array{language: string, name: string}>} */
    public static function of(ServiceFamily $family): array
    {
        return [
            'id' => (int) $family->getId(),
            'name' => $family->getName(),
            'position' => $family->getPosition(),
            'translations' => array_values(array_map(fn ($t) => ['language' => $t->getLanguage(), 'name' => $t->getName()], $family->getTranslations()->toArray())),
        ];
    }
}
