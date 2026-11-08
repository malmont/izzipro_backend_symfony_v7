<?php

namespace App\MemoiresVivantes\Exception;

/**
 * Refus d'une opération sur une invitation de chapitre (08/11/2026) : statut HTTP, code stable (MAJUSCULES_SOULIGNÉES,
 * que l'application web traduit elle-même), message français et paramètres. Table des codes : docs/memoires-vivantes.md,
 * « Invitations par e-mail ».
 */
final class ChapterInvitationException extends \RuntimeException
{
    /** @param array<string, mixed> $params */
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message, public readonly array $params = [])
    {
        parent::__construct($message);
    }

    /** @return array{error: string, code: string, params?: array<string, mixed>} corps JSON de la réponse */
    public function body(): array
    {
        return ['error' => $this->getMessage(), 'code' => $this->errorCode] + ($this->params !== [] ? ['params' => $this->params] : []);
    }
}
