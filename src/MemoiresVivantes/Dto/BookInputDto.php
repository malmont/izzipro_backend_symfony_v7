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

    public function __construct(array $data)
    {
        $this->title = $data['title'] ?? null;
        $this->subtitle = $data['subtitle'] ?? null;
        $this->birthplace = $data['birthplace'] ?? null;
        $this->format = $data['format'] ?? null;
        $typeInput = $data['type'] ?? null;
        $this->type = ($typeInput !== null && in_array($typeInput, ['individuel', 'couple', 'famille'], true)) ? $typeInput : null;
        $this->person1FirstName = $data['person1FirstName'] ?? null;
        $this->person1Birthplace = $data['person1Birthplace'] ?? null;
        $this->person2FirstName = $data['person2FirstName'] ?? null;
        $this->person2Birthplace = $data['person2Birthplace'] ?? null;
    }
}
