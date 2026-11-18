<?php

namespace App\LossPrevention;

use App\LossPrevention\Services\LossPairingService;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Lot L6-b (17/11/2026, `docs/prevention-pertes.md` § 19.5) : les saisies de code de jumelage refusées sont écrites aux journaux APRÈS
 * la réponse (décision du chef de projet) : un code inconnu et un code réservé à un autre site gardent la même durée de réponse, et l'on
 * garde la trace des tentatives.
 */
class LossPairingTraceSubscriber implements EventSubscriberInterface
{
    /**
     * Train 102 : sans cela, CHAQUE requête construisait le service de jumelage et sa chaîne (envoi des courriels compris) à la fin de la
     * réponse. Le service partagé n'est demandé (par la fermeture) que si une requête a noté un refus ; c'est alors l'instance qui l'a noté.
     *
     * @param \Closure(): LossPairingService $pairing
     */
    public function __construct(#[AutowireServiceClosure(LossPairingService::class)] private readonly \Closure $pairing)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'onTerminate'];
    }

    public function onTerminate(): void
    {
        if (!LossPairingService::hasPendingRefusals()) {
            return;
        }
        ($this->pairing)()->flushRefusals();
    }
}
