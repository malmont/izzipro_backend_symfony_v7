<?php

namespace App\MemoiresVivantes\Dto;

class BookInputDto
{
    public ?string $title = null;
    public ?string $subtitle = null;
    public ?string $birthplace = null;
    public ?string $format = null;
    public ?string $type = null;
    public ?string $person1FirstName = null;
    public ?string $person1Birthplace = null;
    public ?string $person2FirstName = null;
    public ?string $person2Birthplace = null;
    public ?string $birthYear = null;
    public ?string $deathYear = null;
    public ?string $epigraph = null;
    public ?bool $parentsDeceased = null;
    public ?bool $parentsNotParticipating = null;

    public function __construct(array $data)
    {
        $this->title = $data['title'] ?? null;
        $this->subtitle = $data['subtitle'] ?? null;
        $this->birthplace = $data['birthplace'] ?? null;
        $this->format = $data['format'] ?? null;
        $typeInput = $data['type'] ?? null;
        $this->type = ($typeInput !== null && in_array($typeInput, ['individuel', 'couple', 'famille', 'hommage'], true)) ? $typeInput : null;
        $this->person1FirstName = $data['person1FirstName'] ?? null;
        $this->person1Birthplace = $data['person1Birthplace'] ?? null;
        $this->person2FirstName = $data['person2FirstName'] ?? null;
        $this->person2Birthplace = $data['person2Birthplace'] ?? null;
        $this->birthYear = isset($data['birthYear']) ? (string)$data['birthYear'] : ($data['birth_year'] ?? null);
        $this->deathYear = isset($data['deathYear']) ? (string)$data['deathYear'] : ($data['death_year'] ?? null);
        $this->epigraph = $data['epigraph'] ?? null;

        $deceased = $data['parentsDeceased'] ?? $data['parents_deceased'] ?? $data['parentsNotParticipating'] ?? $data['parents_not_participating'] ?? null;
        $this->parentsDeceased = $deceased !== null ? (bool)$deceased : null;
        $this->parentsNotParticipating = $this->parentsDeceased;
    }
}
