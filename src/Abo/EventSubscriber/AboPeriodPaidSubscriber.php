<?php

namespace App\Abo\EventSubscriber;

use App\Abo\Services\AboRightsService;
use App\Event\SubscriptionPeriodPaidEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Lot ABO-1 (19/11/2026) : une facture payée fait naître les droits de sa période. Une erreur remonte au webhook, qui la journalise et ne
 * marque pas la période traitée : le passage quotidien (`app:abo:periodes`) la réémet.
 */
class AboPeriodPaidSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AboRightsService $rights)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [SubscriptionPeriodPaidEvent::class => 'onPeriodPaid'];
    }

    public function onPeriodPaid(SubscriptionPeriodPaidEvent $event): void
    {
        $this->rights->birth($event->subscriptionId, $event->invoiceId, $event->periodStart, $event->periodEnd, $event->amountPaid, $event->billingReason);
    }
}
