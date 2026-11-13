<?php

namespace App\Gestion\UseCase;

use App\Gestion\Lexique\LexiqueService;

/** Lexique de la gestion (lot W2-APPRENDRE, A-1 et A-3, 13/11/2026, `docs/lexique-gestion.md`) : cas d'usage des quatre routes, sans IA */
class GestionLexiqueUseCase
{
    public function __construct(private readonly LexiqueService $lexique)
    {
    }

    /** @return array{version: string, entries: list<array<string, mixed>>} */
    public function list(string $locale): array
    {
        return $this->lexique->list($locale);
    }

    /** @return array<string, mixed> */
    public function entry(string $key, string $locale): array
    {
        return $this->lexique->entry($key, $locale);
    }

    /** @return array<string, mixed> */
    public function quiz(string $key, string $locale): array
    {
        return $this->lexique->quiz($key, $locale);
    }

    /** @param array<string, mixed> $body @return array{correct: bool, correctChoice: string, explanation: string} */
    public function answer(string $key, string $question, array $body, string $locale): array
    {
        return $this->lexique->answer($key, $question, $body['choice'] ?? null, $locale);
    }
}
