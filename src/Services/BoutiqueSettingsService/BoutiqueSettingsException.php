<?php

namespace App\Services\BoutiqueSettingsService;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** Refus d'une écriture des réglages de la boutique : statut, message et erreurs par champ (path, message) */
final class BoutiqueSettingsException extends HttpException
{
    /** @param list<array{path: string, message: string}> $errors */
    public function __construct(int $status, string $message, public readonly array $errors = [])
    {
        parent::__construct($status, $message);
    }
}
