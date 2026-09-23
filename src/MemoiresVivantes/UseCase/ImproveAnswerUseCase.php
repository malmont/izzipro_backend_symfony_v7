<?php

namespace App\MemoiresVivantes\UseCase;

use App\Services\AnthropicService;

class ImproveAnswerUseCase
{
    public function __construct(
        private readonly AnthropicService $anthropicService
    ) {}

    /**
     * Améliore et reformule une réponse d'interview via Claude (Anthropic).
     *
     * @param string $question
     * @param string $answer
     * @return string Texte enrichi et amélioré
     */
    public function execute(string $question, string $answer, ?string $model = null): string
    {
        return $this->anthropicService->improveAnswer($question, $answer, $model);
    }
}
