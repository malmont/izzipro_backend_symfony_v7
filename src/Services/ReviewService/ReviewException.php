<?php

namespace App\Services\ReviewService;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** Refus d'une opération sur les avis : statut HTTP, message, erreurs par champ (path, message), raison codée */
final class ReviewException extends HttpException
{
    /** @param list<array{path: string, message: string}> $errors */
    public function __construct(int $status, string $message, public readonly array $errors = [], public readonly ?string $reason = null)
    {
        parent::__construct($status, $message);
    }
}
