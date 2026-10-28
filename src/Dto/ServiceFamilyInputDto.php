<?php

namespace App\Dto;

/**
 * Corps de POST /api/service-families et PUT /api/service-families/{id} : { name, position?, translations?: [{ language, name }] }.
 * PUT : chaque clé présente remplace la valeur ; translations présente remplace toutes les traductions.
 */
final class ServiceFamilyInputDto
{
    public const MAX_NAME_LENGTH = 255;
    public const MAX_TRANSLATIONS = 10;

    public ?string $name = null;
    public ?int $position = null;
    /** @var list<array{language: string, name: string}>|null */
    public ?array $translations = null;
    /** @var list<array{path: string, message: string}> */
    public array $errors = [];

    public static function fromBody(mixed $body, bool $nameRequired): self
    {
        $dto = new self();
        if (!is_object($body)) {
            $dto->errors[] = ['path' => '', 'message' => 'objet JSON attendu'];

            return $dto;
        }
        if (property_exists($body, 'name') || $nameRequired) {
            $name = is_string($body->name ?? null) ? trim($body->name) : '';
            self::validName($name) ? $dto->name = $name : $dto->errors[] = ['path' => 'name', 'message' => sprintf('nom de 1 à %d caractères, sans < ni >', self::MAX_NAME_LENGTH)];
        }
        if (property_exists($body, 'position') && $body->position !== null) {
            is_int($body->position) && $body->position >= 0 && $body->position <= 100000 ? $dto->position = $body->position : $dto->errors[] = ['path' => 'position', 'message' => 'entier de 0 à 100000 attendu'];
        }
        if (property_exists($body, 'translations') && $body->translations !== null) {
            if (!is_array($body->translations) || count($body->translations) > self::MAX_TRANSLATIONS) {
                $dto->errors[] = ['path' => 'translations', 'message' => sprintf('liste de %d traductions au plus attendue', self::MAX_TRANSLATIONS)];
            } else {
                $dto->translations = [];
                $languages = [];
                foreach ($body->translations as $i => $translation) {
                    $language = is_object($translation) && is_string($translation->language ?? null) ? $translation->language : null;
                    $name = is_object($translation) && is_string($translation->name ?? null) ? trim($translation->name) : '';
                    if ($language === null || !preg_match('/^[a-z]{2}$/', $language) || isset($languages[$language])) {
                        $dto->errors[] = ['path' => "translations[$i].language", 'message' => 'code de langue à 2 lettres attendu, une fois par langue'];
                    } elseif (!self::validName($name)) {
                        $dto->errors[] = ['path' => "translations[$i].name", 'message' => sprintf('nom de 1 à %d caractères, sans < ni >', self::MAX_NAME_LENGTH)];
                    } else {
                        $languages[$language] = true;
                        $dto->translations[] = ['language' => $language, 'name' => $name];
                    }
                }
            }
        }

        return $dto;
    }

    private static function validName(string $name): bool
    {
        return $name !== '' && mb_strlen($name) <= self::MAX_NAME_LENGTH && !preg_match('/[<>]/', $name);
    }
}
