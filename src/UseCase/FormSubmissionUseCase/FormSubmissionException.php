<?php

namespace App\UseCase\FormSubmissionUseCase;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** Refus d'une demande sur les demandes reçues : statut HTTP, message, erreurs { path, message } */
final class FormSubmissionException extends HttpException
{
    /** @param list<array{path: string, message: string}> $errors */
    public function __construct(int $status, string $message, public readonly array $errors = [])
    {
        parent::__construct($status, $message);
    }
}
