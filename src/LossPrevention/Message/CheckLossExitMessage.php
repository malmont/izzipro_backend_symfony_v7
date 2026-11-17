<?php

namespace App\LossPrevention\Message;

/** Contrôler, deux minutes plus tard, une lecture à la sortie (lot L5-d, `docs/prevention-pertes.md` § 18.11) : file `email` */
final class CheckLossExitMessage
{
    public function __construct(public readonly int $eventId, public readonly string $tenantCode, public readonly ?int $requestedBy = null)
    {
    }
}
