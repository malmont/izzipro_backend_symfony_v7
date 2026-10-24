<?php

namespace App\Services\RetentionService;

use App\Services\CaisseService\CashRegisterException;

/** Donnée de vente sous conservation légale : suppression refusée (ou modification refusée en mode « block »). 409 en API, message clair dans EasyAdmin. */
class RetentionViolationException extends CashRegisterException
{
    public function __construct(string $message, array $context = [])
    {
        parent::__construct('RETENTION_LOCKED', $message, 409, [], $context);
    }
}
