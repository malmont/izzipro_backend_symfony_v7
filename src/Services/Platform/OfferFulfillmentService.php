<?php

namespace App\Services\Platform;

use App\Entity\Subscription;
use App\Event\SubscriptionStatusChangedEvent;
use Psr\Log\LoggerInterface;

/**
 * Traduit la vie d'un abonnement (ou un événement de facturation externe) en droits d'usage du site acheté (console de pilotage,
 * lot 3 bis, 04/11/2026). Événements : `activated` (droits `subscription` pour chaque fonctionnalité de l'offre, fin = fin de période
 * + délai de grâce de la politique), `renewed` (prolongation), `unpaid` (grâce), `paused` (suspension), `resumed`, `cancelled`.
 * Les droits d'un abonnement portent la référence `subscription:<site vendeur>:<id>` (ou la référence externe de billing-events).
 */
class OfferFulfillmentService
{
    public const EVENTS = ['activated', 'renewed', 'unpaid', 'paused', 'resumed', 'cancelled'];

    public function __construct(
        private readonly PlatformOfferRepository $offers,
        private readonly EntitlementService $entitlements,
        private readonly EntitlementRepository $repository,
        private readonly FeaturePolicyRepository $policies,
        private readonly PlatformModeService $platform,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{event: string, features: list<string>, actions: list<string>} ce qui a été fait
     * @throws \InvalidArgumentException événement ou offre inconnus
     */
    public function apply(string $event, string $tenantCode, string $offerCode, string $reference, ?\DateTimeImmutable $periodEnd, ?string $by): array
    {
        if (!in_array($event, self::EVENTS, true)) {
            throw new \InvalidArgumentException('event : ' . implode(', ', self::EVENTS) . ' attendu');
        }
        $offer = $this->offers->find($offerCode);
        if ($offer === null) {
            throw new \InvalidArgumentException(sprintf('offre « %s » inconnue', $offerCode));
        }
        $actions = [];
        $existing = $this->repository->byReference($reference, []);
        $byFeature = [];
        foreach ($existing as $row) {
            if (!in_array($row['status'], ['expired', 'cancelled'], true)) {
                $byFeature[$row['feature_key']] = $row;
            }
        }
        foreach ($offer['features'] as $feature) {
            $endsAt = $this->endsAt($feature, $periodEnd);
            $current = $byFeature[$feature] ?? null;
            switch ($event) {
                case 'activated':
                case 'renewed':
                    if ($current !== null && in_array($current['status'], ['active', 'grace'], true)) {
                        $this->entitlements->extend((int) $current['id'], $endsAt, $by, $event === 'renewed' ? 'renouvellement' : 'réactivation');
                        $actions[] = "$feature: extended";
                    } elseif ($current !== null && $current['status'] === 'suspended') {
                        $this->entitlements->resume((int) $current['id'], $by, 'paiement régularisé');
                        $this->entitlements->extend((int) $current['id'], $endsAt, $by, null);
                        $actions[] = "$feature: resumed";
                    } else {
                        $this->entitlements->grant($tenantCode, $feature, 'subscription', $endsAt, $by, 'offre ' . $offerCode, $offerCode, $reference);
                        $actions[] = "$feature: granted";
                    }
                    break;
                case 'unpaid':
                    if ($current !== null && $current['status'] === 'active') {
                        $this->entitlements->grace((int) $current['id'], $by, 'impayé');
                        $actions[] = "$feature: grace";
                    }
                    break;
                case 'paused':
                    if ($current !== null && in_array($current['status'], ['active', 'grace'], true)) {
                        $this->entitlements->suspend((int) $current['id'], $by, 'abonnement en pause');
                        $actions[] = "$feature: suspended";
                    }
                    break;
                case 'resumed':
                    if ($current !== null && in_array($current['status'], ['suspended', 'grace'], true)) {
                        $this->entitlements->resume((int) $current['id'], $by, 'abonnement repris');
                        $actions[] = "$feature: resumed";
                    }
                    break;
                case 'cancelled':
                    if ($current !== null) {
                        $this->entitlements->cancel((int) $current['id'], $by, 'abonnement résilié');
                        $actions[] = "$feature: cancelled";
                    }
                    break;
            }
        }
        $this->logger->notice(sprintf('[Offers] %s → %s (offre %s, réf. %s) : %s', $event, $tenantCode, $offerCode, $reference, implode(', ', $actions) ?: 'rien à faire'));

        return ['event' => $event, 'features' => $offer['features'], 'actions' => $actions];
    }

    /** Événement d'un abonnement de la boutique (SubscriptionLifecycleSubscriber) → événement d'offre, ou null si rien ne change pour les droits */
    public function fromSubscriptionEvent(SubscriptionStatusChangedEvent $e): ?array
    {
        if ($e->fulfillmentType !== \App\Entity\SubscriptionPlan::FULFILLMENT_PLATFORM_OFFER || $e->fulfillmentRef === null || $e->fulfillmentTarget === null) {
            return null;
        }
        // un seul site gouverne les autres : celui désigné dans la base maître (platform_settings.shop_tenant) ; tout autre est ignoré
        $shopTenant = $this->platform->shopTenant();
        if ($shopTenant === null || $e->tenantCode !== $shopTenant) {
            $this->logger->warning(sprintf('[Offers] abonnement %d branché sur le site %s ignoré : la boutique de la plateforme est %s', $e->subscriptionId, $e->tenantCode ?? '-', $shopTenant ?? 'non désignée'));

            return null;
        }
        $event = self::eventFor($e);
        if ($event === null) {
            return null;
        }
        $reference = sprintf('subscription:%s:%d', $e->tenantCode ?? '-', $e->subscriptionId);

        return $this->apply($event, $e->fulfillmentTarget, $e->fulfillmentRef, $reference, $e->currentPeriodEnd, 'abonnement n° ' . $e->subscriptionId);
    }

    public static function eventFor(SubscriptionStatusChangedEvent $e): ?string
    {
        $live = [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING];
        if (in_array($e->to, $live, true)) {
            if ($e->from === Subscription::STATUS_PAUSED) {
                return 'resumed';
            }
            if (!in_array($e->from, $live, true)) {
                return 'activated';
            }

            return $e->periodChanged() ? 'renewed' : null;
        }

        return match ($e->to) {
            Subscription::STATUS_PAST_DUE => $e->statusChanged() ? 'unpaid' : null,
            Subscription::STATUS_PAUSED => $e->statusChanged() ? 'paused' : null,
            Subscription::STATUS_CANCELED => $e->statusChanged() ? 'cancelled' : null,
            default => null,
        };
    }

    private function endsAt(string $feature, ?\DateTimeImmutable $periodEnd): ?\DateTimeImmutable
    {
        if ($periodEnd === null) {
            return null; // sans fin connue : le droit vit jusqu'à l'événement suivant (impayé, résiliation)
        }
        $grace = $this->policies->get($feature)['grace_days'];
        $end = $periodEnd->modify("+$grace days");

        return $end > new \DateTimeImmutable() ? $end : (new \DateTimeImmutable())->modify('+1 day');
    }
}
