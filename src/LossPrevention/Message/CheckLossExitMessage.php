<?php

namespace App\LossPrevention\Message;

/** Contrôler, deux minutes plus tard, une lecture à la sortie (lot L5-d, `docs/prevention-pertes.md` § 18.11) : file `email` */
final class CheckLossExitMessage
{
    /** `$dueAt` (heure UNIX où le contrôle est attendu) : le handler journalise son retard réel (demande du chef de projet, 17/11/2026) */
    public function __construct(public readonly int $eventId, public readonly string $tenantCode, public readonly ?int $requestedBy = null, public readonly ?int $dueAt = null)
    {
    }
}
