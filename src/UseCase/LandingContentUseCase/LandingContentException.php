<?php

namespace App\UseCase\LandingContentUseCase;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** Refus d'une modification de contenu : statut, message et erreurs par champ (path, message) */
final class LandingContentException extends HttpException
{
    /** @param list<array{path: string, message: string}> $errors */
    public function __construct(int $status, string $message, public readonly array $errors = [])
    {
        parent::__construct($status, $message);
    }
}
