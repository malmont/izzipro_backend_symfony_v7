<?php

namespace App\Import\Message;

/** Tâche de fond (file `media`) : exécuter un import de catalogue confirmé (lot IMP-1) */
final class RunCatalogImportMessage
{
    public function __construct(public readonly int $jobId, public readonly string $tenantCode, public readonly ?string $by = null)
    {
    }
}
