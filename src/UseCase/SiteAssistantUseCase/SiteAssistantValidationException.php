<?php

namespace App\UseCase\SiteAssistantUseCase;

/** Conversation refusée : erreurs par champ (422 VALIDATION_FAILED) */
final class SiteAssistantValidationException extends \RuntimeException
{
    /** @param list<array{path: string, message: string}> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct($errors[0]['path'] . ' : ' . $errors[0]['message']);
    }
}
