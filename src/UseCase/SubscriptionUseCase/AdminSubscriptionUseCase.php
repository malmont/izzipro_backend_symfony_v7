<?php

namespace App\UseCase\SubscriptionUseCase;

use App\Entity\Subscription;
use App\Entity\SubscriptionPlan;
use App\Services\SubscriptionService\SubscriptionException;
use App\Services\SubscriptionService\SubscriptionMailer;
use App\Services\SubscriptionService\SubscriptionService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Actions de l'administration sur l'abonnement d'un client (EasyAdmin, 13/10/2026) : mêmes opérations que le client
 * (résiliation en fin de période ou immédiate, reprise, pause, changement de formule), plus la relecture de l'état
 * chez Stripe et le remboursement du dernier paiement. Le client reçoit les mêmes courriels que s'il avait agi lui-même ;
 * chaque action est tracée dans le journal applicatif avec l'administrateur.
 */
final class AdminSubscriptionUseCase
{
    public function __construct(
        private readonly SubscriptionService $service,
        private readonly SubscriptionMailer $mailer,
        private readonly LoggerInterface $logger,
        private readonly Security $security,
        private readonly ?\App\Abo\Services\AboRightsService $rights = null, // lot ABO-1
    ) {
    }

    /** @throws SubscriptionException */
    public function cancel(Subscription $subscription, bool $atPeriodEnd, string $host): void
    {
        $this->service->cancel($subscription, $atPeriodEnd);
        $this->mailer->notify($subscription, $atPeriodEnd ? 'cancel_scheduled' : 'canceled', $host);
        $this->trace($atPeriodEnd ? 'cancel_at_period_end' : 'cancel_now', $subscription);
    }

    /** @throws SubscriptionException */
    public function resume(Subscription $subscription): void
    {
        $this->service->resume($subscription);
        $this->trace('resume', $subscription);
    }

    /** @throws SubscriptionException */
    public function pause(Subscription $subscription, string $host): void
    {
        if ($this->rights !== null) {
            // lot ABO-1 (relecture neuve) : les règles de la formule (durée maximale, pauses par an) et les droits prolongés, comme partout
            try {
                $this->rights->pause($subscription, null, $this->security->getUser()?->getUserIdentifier());
            } catch (\App\Abo\Services\AboException $e) {
                throw new SubscriptionException($e->status, $e->getMessage(), [], [], $e->errorCode, $e);
            }
        } else {
            $this->service->pause($subscription);
        }
        $this->mailer->notify($subscription, 'paused', $host);
        $this->trace('pause', $subscription);
    }

    /** @throws SubscriptionException */
    public function changePlan(Subscription $subscription, int $planId): void
    {
        $from = $subscription->getPlan()?->getId();
        $this->service->changePlan($subscription, $planId);
        $this->trace('change_plan', $subscription, ['from' => $from, 'to' => $planId]);
    }

    /** @throws SubscriptionException */
    public function sync(Subscription $subscription): void
    {
        $this->service->sync($subscription);
        $this->trace('sync', $subscription, ['status' => $subscription->getStatus()]);
    }

    /**
     * @return array{refundId: string, amount: int, currency: string}
     * @throws SubscriptionException
     */
    public function refundLatest(Subscription $subscription): array
    {
        $refund = $this->service->refundLatest($subscription);
        $this->trace('refund', $subscription, $refund);

        return $refund;
    }

    /** @return list<SubscriptionPlan> autres formules actives du même produit */
    public function otherPlans(Subscription $subscription): array
    {
        $product = $subscription->getPlan()?->getProduct();
        if ($product === null) {
            return [];
        }

        return array_values(array_filter(
            $this->service->plans()->findBy(['product' => $product, 'active' => true], ['id' => 'ASC']),
            fn (SubscriptionPlan $plan) => $plan->getId() !== $subscription->getPlan()?->getId()
        ));
    }

    private function trace(string $action, Subscription $subscription, array $details = []): void
    {
        $this->logger->info('[Subscription] Action de l\'administration : ' . $action, [
            'subscription' => $subscription->getId(), 'stripe' => $subscription->getStripeSubscriptionId(),
            'admin' => $this->security->getUser()?->getUserIdentifier(),
        ] + $details);
    }
}
