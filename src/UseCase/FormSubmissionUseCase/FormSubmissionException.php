<?php

namespace App\UseCase\FormSubmissionUseCase;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** Refus d'une demande sur les demandes reçues : statut HTTP, message, erreurs { path, message } */
final class FormSubmissionException extends HttpException
{
    /**
     * @param list<array{path: string, message: string}> $errors
     * @param string|null $reason code lisible par le frontend (form_not_found, form_changed, slot_taken)
     */
    public function __construct(int $status, string $message, public readonly array $errors = [], public readonly ?string $reason = null)
    {
        parent::__construct($status, $message);
    }
}
