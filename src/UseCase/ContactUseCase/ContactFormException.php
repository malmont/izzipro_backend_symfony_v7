<?php

namespace App\UseCase\ContactUseCase;

/** Formulaire de contact refusé : erreurs par champ, en français */
final class ContactFormException extends \RuntimeException
{
    /** @param list<array{field: string, message: string}> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Formulaire incomplet');
    }
}
