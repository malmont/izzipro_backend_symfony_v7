<?php

namespace App\Services\ContentAuditService;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** Refus d'une lecture ou d'un retour en arrière du journal : statut, message, erreurs éventuelles (path, message) */
final class ContentAuditException extends HttpException
{
    /** @param list<array{path: string, message: string}> $errors */
    public function __construct(int $status, string $message, public readonly array $errors = [])
    {
        parent::__construct($status, $message);
    }
}
