<?php

namespace App\Dto;

/**
 * Réponse de POST /api/landingpage-ai/compose : 200 avec la proposition (edit, create), ou 202 avec la tâche de
 * fond à interroger (page, images).
 */
final class LandingAiComposeOutputDto
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly array $headers = []
    ) {
    }
}
