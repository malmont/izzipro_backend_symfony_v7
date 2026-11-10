<?php

namespace App\Message;

/** Rédaction par l'IA des conseils d'une génération du guide (lot G1a, 11/11/2026), transport `gestion_ai` (worker de la gestion) */
final class GenerateGestionAdviceMessage
{
    public function __construct(public readonly int $runId, public readonly string $tenantCode)
    {
    }
}
