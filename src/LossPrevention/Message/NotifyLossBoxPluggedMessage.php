<?php

namespace App\LossPrevention\Message;

/**
 * Prévenir les administrateurs du site acheteur (lot L6-b, `docs/prevention-pertes.md` § 19.5), file `email` : leur boîtier est branché
 * (`plugged`), ou l'expédition d'un boîtier branché a été annulée par la plateforme (`cancelled` : écrit aussi au journal du site).
 */
final class NotifyLossBoxPluggedMessage
{
    public const PLUGGED = 'plugged';
    public const CANCELLED = 'cancelled';

    public function __construct(public readonly string $tenantCode, public readonly int $shipmentId, public readonly string $what = self::PLUGGED)
    {
    }
}
