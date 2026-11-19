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
    public function apply(string $event, string $tenantCode, string $offerCode, string $reference, ?\DateTimeImmutable $periodEnd, ?string $by, array $skipFeatures = []): array
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
            if (in_array($feature, $skipFeatures, true)) {
                $actions[] = "$feature: covered"; // lot PROVISION-3 : un droit d'une autre origine couvre déjà la fonctionnalité
                continue;
            }
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

    /**
     * Lot PROVISION-3 : les droits VOULUS d'un abonnement branché, recalculés depuis son état (file de reprise, réconciliation), sans
     * rejouer d'événement ; sans danger à répéter. Ne touche que les droits de CET abonnement (référence `subscription:<site>:<id>`), jamais
     * un droit d'une autre origine. Rend les actions faites (vide : abonnement non branché, ou rien à poser).
     *
     * @return list<string>
     */
    public function resync(Subscription $subscription, string $shopTenant): array
    {
        $plan = $subscription->getPlan();
        if ($plan === null || $plan->getFulfillmentType() !== \App\Entity\SubscriptionPlan::FULFILLMENT_PLATFORM_OFFER || $plan->getFulfillmentRef() === null
            || $subscription->getFulfillmentTarget() === null || $this->platform->shopTenant() !== $shopTenant) {
            return [];
        }
        $reference = sprintf('subscription:%s:%d', $shopTenant, (int) $subscription->getId());
        // accorder une fonctionnalité annule tout autre droit vivant de la même fonctionnalité (EntitlementService::grant) : une
        // fonctionnalité déjà couverte par un droit d'une autre origine (manuel, essai, gratuit, agence, autre abonnement) n'est pas touchée
        $offer = $this->offers->find($plan->getFulfillmentRef());
        $covered = $offer !== null ? $this->coveredElsewhere($subscription->getFulfillmentTarget(), $offer['features'], $reference, $subscription->getCurrentPeriodEnd()) : [];
        $actions = [];
        foreach (self::wantedEvents($subscription->getStatus()) as $event) {
            // la couverture ne vaut que pour ACCORDER : retirer, suspendre ou mettre en grâce ne touche que les droits de cet abonnement
            $actions = [...$actions, ...$this->apply($event, $subscription->getFulfillmentTarget(), $plan->getFulfillmentRef(), $reference, $subscription->getCurrentPeriodEnd(), 'reprise de l\'abonnement n° ' . $subscription->getId(), $event === 'activated' ? $covered : [])['actions']];
        }

        return $actions;
    }

    /**
     * Lot PROVISION-3 : les fonctionnalités dont le droit ne suit pas l'état de l'abonnement (réconciliation). Actif ou essai : un droit
     * actif jusqu'à la fin de période au moins ; impayé : pas de droit encore « actif » (la grâce, ou expiré après elle) ; pause : pas de droit
     * effectif ; résilié ou incomplet : aucun droit vivant.
     *
     * @return list<string>
     */
    public function drift(Subscription $subscription, string $shopTenant): array
    {
        $plan = $subscription->getPlan();
        if ($plan === null || $plan->getFulfillmentType() !== \App\Entity\SubscriptionPlan::FULFILLMENT_PLATFORM_OFFER || $plan->getFulfillmentRef() === null
            || $subscription->getFulfillmentTarget() === null || $this->platform->shopTenant() !== $shopTenant) {
            return [];
        }
        $offer = $this->offers->find($plan->getFulfillmentRef());
        if ($offer === null) {
            return [];
        }
        $byFeature = [];
        foreach ($this->repository->byReference(sprintf('subscription:%s:%d', $shopTenant, (int) $subscription->getId()), []) as $row) {
            if (!in_array($row['status'], ['expired', 'cancelled'], true)) {
                $byFeature[$row['feature_key']] = $row;
            }
        }
        $periodEnd = $subscription->getCurrentPeriodEnd();
        $live = in_array($subscription->getStatus(), [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING], true);
        $covered = $live ? $this->coveredElsewhere($subscription->getFulfillmentTarget(), $offer['features'], sprintf('subscription:%s:%d', $shopTenant, (int) $subscription->getId()), $periodEnd) : [];
        $off = [];
        foreach ($offer['features'] as $feature) {
            if (in_array($feature, $covered, true)) {
                continue; // couverte par un droit d'une autre origine : rien à corriger, jamais retiré
            }
            $status = $byFeature[$feature]['status'] ?? null;
            $wrong = match ($subscription->getStatus()) {
                Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING => $status !== 'active'
                    || ($periodEnd !== null && $byFeature[$feature]['ends_at'] !== null && strtotime($byFeature[$feature]['ends_at'] . ' UTC') < $periodEnd->getTimestamp()),
                Subscription::STATUS_PAST_DUE => $status === 'active',
                Subscription::STATUS_PAUSED => in_array($status, ['active', 'grace'], true),
                default => $status !== null,
            };
            if ($wrong) {
                $off[] = $feature;
            }
        }

        return $off;
    }

    /**
     * Les fonctionnalités dont un droit VIVANT (actif ou en grâce) vient d'une autre référence que `$reference` et dure au moins autant que
     * le droit voulu (sans fin, ou jusqu'à la fin de période + grâce) : un essai qui finit avant ne couvre pas, le client payant le garde.
     *
     * @param list<string> $features
     * @return list<string>
     */
    private function coveredElsewhere(string $tenantCode, array $features, string $reference, ?\DateTimeImmutable $periodEnd): array
    {
        $covered = [];
        foreach ($features as $feature) {
            $wanted = $this->endsAt($feature, $periodEnd);
            foreach ($this->repository->liveFor($tenantCode, $feature) as $row) {
                $lasts = ($row['ends_at'] ?? null) === null || ($wanted !== null && strtotime($row['ends_at'] . ' UTC') >= $wanted->getTimestamp());
                if (($row['reference'] ?? null) !== $reference && $lasts) {
                    $covered[] = $feature;
                    break;
                }
            }
        }

        return $covered;
    }

    /** @return list<string> les événements qui mènent aux droits voulus pour un statut */
    public static function wantedEvents(string $status): array
    {
        return match ($status) {
            Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING => ['activated'],
            Subscription::STATUS_PAST_DUE => ['unpaid'],
            Subscription::STATUS_PAUSED => ['paused'],
            Subscription::STATUS_CANCELED, Subscription::STATUS_INCOMPLETE, 'incomplete_expired' => ['cancelled'], // jamais payé : aucun droit
            default => [],
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
