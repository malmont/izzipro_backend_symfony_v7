<?php

namespace App\MemoiresVivantes\Message;

class GenerateChapterMessage
{
    public function __construct(
        public readonly string $chapterId,
        public readonly int $part, // 1 ou 2
        public readonly string $tenantHost,
        public readonly string $tone = 'intime et chaleureux'
    ) {}
}
