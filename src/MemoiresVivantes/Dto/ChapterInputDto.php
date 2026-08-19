<?php

namespace App\MemoiresVivantes\Dto;

class ChapterInputDto
{
    public ?string $title = null;
    public ?string $theme = null;
    public ?int $position = null;
    public array $answers = [];
    public ?array $contributorAnswers = null;

    public function __construct(array $data)
    {
        $this->title = $data['title'] ?? null;
        $this->theme = $data['theme'] ?? null;
        $this->position = $data['position'] ?? null;
        $this->answers = $data['answers'] ?? [];
        $this->contributorAnswers = $data['contributorAnswers'] ?? null;
    }
}
