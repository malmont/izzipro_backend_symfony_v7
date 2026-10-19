<?php

namespace App\UseCase\LandingAiUseCase;

use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\LandingAiService\LandingAiException;
use App\Services\LandingAiService\LandingAiInstructionsService;

/**
 * GET / PUT /api/landingpage-ai/instructions : consignes permanentes de l'assistant pour le site courant. Chaque
 * écriture est inscrite au journal (resource « landingpage-ai-instructions », rétablissable par
 * POST /api/landingpage-audit/{id}/restore).
 */
class ManageLandingAiInstructionsUseCase
{
    public const AUDIT_RESOURCE = 'landingpage-ai-instructions';

    public function __construct(
        private readonly LandingAiInstructionsService $instructions,
        private readonly ContentAuditRecorder $audit
    ) {
    }

    /** @return array{instructions: ?string, updatedAt: ?string, maxLength: int} */
    public function get(): array
    {
        return $this->instructions->describe();
    }

    /**
     * @return array{instructions: ?string, updatedAt: ?string, maxLength: int}
     * @throws LandingAiException 400
     */
    public function update(mixed $body): array
    {
        if (!is_object($body) || !property_exists($body, 'instructions')) {
            throw LandingAiException::badRequest('Corps attendu : { "instructions": texte ou null }.', [['path' => 'instructions', 'message' => 'champ obligatoire (null pour effacer)']]);
        }
        if ($errors = LandingAiInstructionsService::validate($body->instructions)) {
            throw LandingAiException::badRequest('Consignes invalides : ' . $errors[0]['message'] . '.', $errors);
        }
        [$before, $after] = $this->instructions->save($body->instructions);
        if ($before !== $after) {
            $this->audit->record(self::AUDIT_RESOURCE, null, 'update', ['instructions' => $before], ['instructions' => $after], ['instructions']);
        }

        return $this->instructions->describe();
    }
}
