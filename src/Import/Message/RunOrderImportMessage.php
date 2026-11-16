<?php

namespace App\Import\Message;

/** Tâche de fond (file `media`) : exécuter un import de commandes confirmé (lot IMP-3) */
final class RunOrderImportMessage
{
    public function __construct(public readonly int $jobId, public readonly string $tenantCode, public readonly ?string $by = null)
    {
    }
}
