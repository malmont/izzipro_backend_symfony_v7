<?php

namespace App\LossPrevention;

use App\LossPrevention\Services\LossPairingService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Lot L6-b (17/11/2026, `docs/prevention-pertes.md` § 19.5) : les saisies de code de jumelage refusées sont écrites aux journaux APRÈS
 * la réponse (décision du chef de projet) : un code inconnu et un code réservé à un autre site gardent la même durée de réponse, et l'on
 * garde la trace des tentatives.
 */
class LossPairingTraceSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly LossPairingService $pairing)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'onTerminate'];
    }

    public function onTerminate(): void
    {
        $this->pairing->flushRefusals();
    }
}
