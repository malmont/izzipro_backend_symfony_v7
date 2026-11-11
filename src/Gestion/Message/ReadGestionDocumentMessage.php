<?php

namespace App\Gestion\Message;

/** Lecture par IA d'un document de l'application de gestion (lot L1, 10/11/2026), transport `gestion_ai` (worker dédié) */
final class ReadGestionDocumentMessage
{
    public function __construct(public readonly int $documentId, public readonly string $tenantCode)
    {
    }
}
