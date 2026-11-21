<?php

namespace App\Abo\Event;

/**
 * Lot ABO-4 (21/11/2026) : une place a peut-être été libérée sur ce créneau (date annulée ou déplacée, place retenue rendue, pause,
 * capacité changée). Envoyé APRÈS la transaction qui l'a libérée ; la liste d'attente la propose à la personne suivante, sous le verrou
 * du créneau (`AboWaitlistService::onSlotFreed`). Jamais envoyé par une lecture.
 */
final class SlotFreedEvent
{
    public function __construct(public readonly int $slotId)
    {
    }
}
