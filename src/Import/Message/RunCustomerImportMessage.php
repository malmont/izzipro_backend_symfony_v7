<?php

namespace App\Import\Message;

/** Tâche de fond (file `media`) : exécuter un import de clients confirmé (lot IMP-2) */
final class RunCustomerImportMessage
{
    public function __construct(public readonly int $jobId, public readonly string $tenantCode, public readonly ?string $by = null)
    {
    }
}
