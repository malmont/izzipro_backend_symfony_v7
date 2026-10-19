<?php

namespace App\Services\LandingAiService;

/**
 * Le modèle a posé des questions au lieu de composer (LandingAiQuestions) : aucune composition, aucun crédit facturé ;
 * la console montre les questions puis renvoie la même demande avec les réponses (answers).
 */
final class LandingAiQuestionsResult
{
    /**
     * @param list<array{id: string, question: string, options: list<string>, multiple: bool}> $questions
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly array $questions,
        public readonly string $summary,
        public readonly array $warnings,
        public readonly LandingAiUsageStats $stats
    ) {
    }
}
