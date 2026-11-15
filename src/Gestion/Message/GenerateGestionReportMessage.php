<?php

namespace App\Gestion\Message;

/** Tâche de fond (file `pdf`) : fabriquer le fichier d'un rapport de la gestion (lot DOC-1) */
final class GenerateGestionReportMessage
{
    public function __construct(public readonly int $reportId, public readonly string $tenantCode)
    {
    }
}
