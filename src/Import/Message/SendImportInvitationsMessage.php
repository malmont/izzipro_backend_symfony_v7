<?php

namespace App\Import\Message;

/** Tâche de fond (file `media`) : envoyer les invitations des clients repris par un import (lot IMP-2), une par invité, une seule fois */
final class SendImportInvitationsMessage
{
    public function __construct(public readonly int $jobId, public readonly string $tenantCode)
    {
    }
}
