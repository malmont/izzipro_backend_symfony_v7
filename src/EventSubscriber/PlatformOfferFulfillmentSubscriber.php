<?php

namespace App\EventSubscriber;

use App\Event\SubscriptionStatusChangedEvent;
use App\Services\Platform\OfferFulfillmentService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Seul écouteur du branchement `platform_offer` (console de pilotage, lot 3 bis, 04/11/2026) : un abonnement du site de la plateforme
 * à une formule branchée sur une offre accorde, prolonge, met en grâce, suspend ou retire les droits d'usage du site acheté. Les
 * abonnements ordinaires (`none`) ne le concernent pas. Un échec est journalisé, jamais remonté à la boutique.
 */
class PlatformOfferFulfillmentSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly OfferFulfillmentService $fulfillment, private readonly LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [SubscriptionStatusChangedEvent::class => 'onStatusChanged'];
    }

    public function onStatusChanged(SubscriptionStatusChangedEvent $event): void
    {
        try {
            $this->fulfillment->fromSubscriptionEvent($event);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Offers] abonnement %d (%s → %s) : droits non mis à jour : %s', $event->subscriptionId, $event->from, $event->to, $e->getMessage()));
        }
    }
}
