<?php

namespace App\UseCase\SubscriptionUseCase;

use App\Entity\Subscription;
use App\Services\OrderService\OrderMailerService;
use App\Services\SubscriptionService\SubscriptionMailer;
use App\Services\SubscriptionService\SubscriptionOrderFactory;
use App\Services\SubscriptionService\SubscriptionService;
use App\Services\SubscriptionService\SubscriptionException;
use App\Services\SubscriptionService\SubscriptionStripeGateway;
use App\Services\SubscriptionService\SubscriptionLock;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;

/**
 * Webhooks Stripe Billing (POST /api/stripe/webhook) : invoice.paid → commande de l'échéance, abonnement actif, impayé
 * effacé ; invoice.payment_failed → voir paymentFailed ; customer.subscription.updated / deleted / paused / resumed →
 * état recopié, courriel de résiliation. Le site vient des métadonnées posées à la souscription (tenant_code) ; un évènement sans site ou sans
 * abonnement connu est ignoré.
 *
 * Lot PROVISION-3 (18/11/2026, `docs/console-pilotage.md`, « Paiements des offres ») :
 * - `handle` tient le JOURNAL des événements (base maître `stripe_event_log`, sans contenu) : un événement déjà traité répond sans rien
 *   refaire ; un échec est noté puis remonté (Stripe livre de nouveau) ;
 * - ORDRE : un événement plus ancien que le dernier appliqué à l'abonnement (`stripe_event_at`) est ignoré (`out_of_order`) ; une facture
 *   payée ne fait jamais reculer la fin de période et ne ressuscite jamais un abonnement résilié ;
 * - « PÉRIODE PAYÉE » : `SubscriptionPeriodPaidEvent` est émis une seule fois par facture payée (registre `subscription_period_paid`).
 */
class HandleSubscriptionWebhookUseCase
{
    /** Lot PROVISION-3 : un événement d'un abonnement inconnu, plus récent que ce délai, est renvoyé à Stripe (409) au lieu d'être ignoré */
    public const UNKNOWN_RETRY_SECONDS = 600;

    public const TYPES = ['invoice.paid', 'invoice.payment_succeeded', 'invoice.payment_failed', 'customer.subscription.updated', 'customer.subscription.deleted', 'customer.subscription.paused', 'customer.subscription.resumed'];

    public function __construct(
        private readonly TenantConnectionManager $connectionManager,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly SubscriptionService $service,
        private readonly SubscriptionOrderFactory $orders,
        private readonly SubscriptionMailer $mailer,
        private readonly OrderMailerService $orderMailer,
        private readonly LoggerInterface $logger,
        private readonly ?\App\Services\Platform\OfferPayments\OfferPaymentsRepository $payments = null, // lot PROVISION-3
        private readonly ?\Symfony\Contracts\EventDispatcher\EventDispatcherInterface $dispatcher = null, // lot PROVISION-3 : « période payée »
    ) {
    }

    /**
     * Lot PROVISION-3 : l'événement passe par le journal. Sans identifiant (essais anciens) : traité directement.
     *
     * @param array<string, mixed> $object data.object de l'évènement
     * @return array{status: string, detail?: string}
     */
    public function handle(string $type, array $object, string $host, ?string $eventId, ?int $created, ?string $account): array
    {
        if ($eventId === null || $this->payments === null) {
            return $this->execute($type, $object, $host, $created);
        }
        $objectId = str_starts_with($type, 'invoice.') ? self::invoiceSubscriptionId($object) : (string) ($object['id'] ?? '');
        try {
            $claimed = $this->payments->claim($eventId, $type, $account, self::metadata($object, 'tenant_code'), $objectId, $created);
        } catch (\PDOException $e) {
            // journal injoignable (table absente, base maître en panne) : l'événement est traité sans journal plutôt que refusé
            $this->logger->critical('[Subscription] journal des événements Stripe injoignable : traité sans journal', ['exception' => $e::class]);

            return $this->execute($type, $object, $host, $created);
        }
        if ($claimed === null) {
            // déjà traité ou ignoré : 200 sans rien refaire ; encore en cours ailleurs : 409, pour que Stripe livre de nouveau plus tard
            return $this->payments->status($eventId) === 'received'
                ? ['status' => 'in_progress', 'detail' => 'événement en cours de traitement']
                : ['status' => 'duplicate', 'detail' => 'événement déjà reçu'];
        }
        try {
            $result = $this->execute($type, $object, $host, $created);
        } catch (\App\Services\SubscriptionService\SubscriptionLockTimeout $e) {
            // l'abonnement est tenu par un autre traitement : l'événement sera repris à la prochaine livraison
            $this->payments->fail($eventId, $e);

            return ['status' => 'in_progress', 'detail' => 'abonnement en cours de traitement'];
        } catch (\Throwable $e) {
            $this->payments->fail($eventId, $e);
            throw $e;
        }
        if ($result['status'] === 'in_progress') {
            $this->payments->fail($eventId, null);

            return $result;
        }
        $this->payments->finish($eventId, $result['status'] === 'processed' ? 'processed' : 'ignored', $result['detail'] ?? null);

        return $result;
    }

    /**
     * @param array<string, mixed> $object data.object de l'évènement
     * @return array{status: string, detail?: string}
     */
    public function execute(string $type, array $object, string $host, ?int $eventCreated = null): array
    {
        $tenantCode = self::metadata($object, 'tenant_code');
        if ($tenantCode === null) {
            return ['status' => 'ignored', 'detail' => 'sans tenant_code'];
        }
        $tenant = $this->connectionManager->findTenantByCode($tenantCode);
        if (!$tenant || empty($tenant['dbname'])) {
            return ['status' => 'ignored', 'detail' => 'site inconnu'];
        }
        $this->emProvider->switchTenant($tenant['dbname'], $tenantCode);

        $stripeId = str_starts_with($type, 'invoice.') ? self::invoiceSubscriptionId($object) : (string) ($object['id'] ?? '');
        if ($stripeId === '') {
            return ['status' => 'ignored', 'detail' => 'abonnement inconnu'];
        }
        // lot PROVISION-3 : un seul traitement à la fois par abonnement (Stripe livre en parallèle), l'abonnement relu sous le verrou
        return SubscriptionLock::run($this->emProvider->getEntityManager()->getConnection(), SubscriptionLock::key($tenantCode, $stripeId),
            fn () => $this->executeLocked($type, $object, $host, $eventCreated, $tenantCode, $stripeId));
    }

    /** @return array{status: string, detail?: string} */
    private function executeLocked(string $type, array $object, string $host, ?int $eventCreated, string $tenantCode, string $stripeId): array
    {
        $subscription = $this->service->subscriptions()->findOneByStripeId($stripeId);
        if ($subscription === null) {
            // événement récent d'un abonnement pas encore enregistré (course à la création) : 409, Stripe livre de nouveau
            if ($eventCreated !== null && time() - $eventCreated < self::UNKNOWN_RETRY_SECONDS) {
                return ['status' => 'in_progress', 'detail' => 'abonnement pas encore enregistré'];
            }

            return ['status' => 'ignored', 'detail' => 'abonnement inconnu'];
        }
        $locale = 'fr';
        $em = $this->emProvider->getEntityManager();
        $em->refresh($subscription);
        // lot PROVISION-3 : un événement plus ancien que le dernier instantané appliqué (événement d'abonnement, relecture chez Stripe,
        // geste local) ne change rien à l'état ; seuls ces instantanés font avancer `stripe_event_at`, jamais une facture. La résiliation
        // (`deleted`), état final chez Stripe, s'applique toujours
        $eventAt = $eventCreated !== null ? (new \DateTimeImmutable())->setTimestamp($eventCreated) : null;
        $outOfOrder = $eventAt !== null && $subscription->getStripeEventAt() !== null && $eventAt < $subscription->getStripeEventAt()
            && $type !== 'customer.subscription.deleted';

        switch ($type) {
            case 'invoice.paid':
            case 'invoice.payment_succeeded':
                if (($object['paid'] ?? true) === false || (int) ($object['amount_paid'] ?? 0) === 0 && (int) ($object['total'] ?? 0) > 0) {
                    return ['status' => 'ignored', 'detail' => 'facture non payée'];
                }
                // la commande de l'échéance est créée même en retard (l'argent est encaissé) ; l'état, lui, n'est pas touché
                $order = $this->orders->createFromInvoice($subscription, $object);
                $this->keepPaymentMethod($subscription, $object);
                $periodEnd = $object['lines']['data'][0]['period']['end'] ?? null;
                if (!$outOfOrder && $subscription->getStatus() !== Subscription::STATUS_CANCELED) {
                    $subscription->setPaymentIssue(null);
                    $subscription->setStatus($subscription->getStatus() === Subscription::STATUS_TRIALING && (int) ($object['total'] ?? 0) === 0 ? Subscription::STATUS_TRIALING : Subscription::STATUS_ACTIVE);
                }
                // lot PROVISION-3 : la fin de période ne recule jamais (une facture payée en retard n'en raccourcit pas une plus récente)
                if (is_numeric($periodEnd) && ($subscription->getCurrentPeriodEnd() === null || (int) $periodEnd > $subscription->getCurrentPeriodEnd()->getTimestamp())) {
                    $subscription->setCurrentPeriodEnd((new \DateTimeImmutable())->setTimestamp((int) $periodEnd));
                }
                $em->flush();
                $this->periodPaid($subscription, $object, $tenantCode);
                if ($order !== null) {
                    $this->orderMailer->sendOrderConfirmation($order, $locale, rtrim($host, '/') . '/');
                }

                return ['status' => 'processed', 'detail' => ($outOfOrder ? 'out_of_order, ' : '') . ($order ? 'commande ' . $order->getId() : 'sans commande : ' . ($this->orders->lastSkipReason ?? '?'))];
            case 'invoice.payment_failed':
                // une facture déjà payée n'est jamais « refusée » après coup, quel que soit l'ordre d'arrivée
                if (is_string($object['id'] ?? null) && $this->payments?->periodPaidExists($tenantCode, $object['id'])) {
                    return ['status' => 'ignored', 'detail' => 'facture déjà payée'];
                }
                // en retard sur un état plus récent : traité seulement si l'abonnement est encore impayé (l'impayé, le courriel et la
                // résiliation au dernier essai ne se perdent pas) ; sinon ignoré
                if ($outOfOrder && $subscription->getStatus() !== Subscription::STATUS_PAST_DUE) {
                    return ['status' => 'ignored', 'detail' => 'out_of_order'];
                }
                // l'échec d'un essai plus ancien que celui déjà noté n'écrase pas l'impayé ni ne renvoie le courriel
                if ((int) ($object['attempt_count'] ?? 1) < (int) ($subscription->getPaymentIssue()['attemptCount'] ?? 0)) {
                    return ['status' => 'ignored', 'detail' => 'essai déjà dépassé'];
                }

                return $this->paymentFailed($subscription, $object, $locale, $host);
            default:
                if ($outOfOrder) {
                    return ['status' => 'ignored', 'detail' => 'out_of_order'];
                }
                $before = $subscription->getStatus();
                $this->service->applyStripeState($subscription, SubscriptionStripeGateway::normalize($object));
                if ($eventAt !== null) {
                    $subscription->noteStripeEventAt($eventAt);
                }
                $em->flush();
                if ($subscription->getStatus() === Subscription::STATUS_CANCELED && $before !== Subscription::STATUS_CANCELED) {
                    $this->mailer->notify($subscription, 'canceled', $locale, $host);
                }

                return ['status' => 'processed', 'detail' => $subscription->getStatus()];
        }
    }

    /**
     * Lot PROVISION-3 : « période payée », une seule fois par facture (invoice.paid et invoice.payment_succeeded de la même facture n'en font
     * qu'une). Les écouteurs (lot ABO-1 : droits nés de la période) ne bloquent jamais le webhook.
     */
    private function periodPaid(Subscription $subscription, array $invoice, string $tenantCode): void
    {
        $invoiceId = $invoice['id'] ?? null;
        if ($this->payments === null || !is_string($invoiceId) || $invoiceId === '') {
            return;
        }
        $period = $invoice['lines']['data'][0]['period'] ?? [];
        $start = is_numeric($period['start'] ?? null) ? (int) $period['start'] : null;
        $end = is_numeric($period['end'] ?? null) ? (int) $period['end'] : null;
        $amount = (int) ($invoice['amount_paid'] ?? 0);
        $reason = is_string($invoice['billing_reason'] ?? null) ? $invoice['billing_reason'] : null;
        if (!$this->payments->recordPeriodPaid($tenantCode, $invoiceId, (int) $subscription->getId(), $start, $end, $amount, $reason)) {
            return;
        }
        try {
            $this->dispatcher?->dispatch(new \App\Event\SubscriptionPeriodPaidEvent((int) $subscription->getId(), $tenantCode, $invoiceId, $start, $end, $amount, $reason));
            $this->payments->markPeriodDispatched($tenantCode, $invoiceId);
        } catch (\Throwable $e) {
            $this->logger->error('[Subscription] « période payée » non traitée', ['subscription' => $subscription->getId(), 'exception' => $e::class]);
        }
    }

    /**
     * Facture payée avec un montant (25/10/2026) : si l'abonnement n'a pas de carte par défaut chez Stripe (essai gratuit
     * dont la carte n'a pas été enregistrée, facture payée ensuite depuis le courriel d'impayé), celle qui vient de payer
     * le devient, pour que les échéances suivantes soient débitées seules. Une erreur Stripe ne bloque jamais le webhook.
     */
    private function keepPaymentMethod(Subscription $subscription, array $invoice): void
    {
        if ((int) ($invoice['amount_paid'] ?? 0) <= 0 || !is_string($invoice['id'] ?? null)) {
            return;
        }
        try {
            if (($paymentMethod = $this->service->ensureDefaultPaymentMethod($subscription, $invoice['id'])) !== null) {
                $this->logger->info('[Subscription] Carte de la facture posée par défaut sur l\'abonnement', ['subscription' => $subscription->getId(), 'invoice' => $invoice['id']]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[Subscription] Carte par défaut non posée après une facture payée', ['subscription' => $subscription->getId(), 'invoice' => $invoice['id'], 'message' => $e->getMessage()]);
        }
    }

    /**
     * Paiement refusé (13/10/2026) :
     * - première facture (abonnement incomplete) : il le reste, le client réessaie depuis la page de souscription (même
     *   abonnement, même clientSecret) ; Stripe l'expire au bout de 23 h (incomplete_expired → résilié) ;
     * - échéance : past_due, impayé gardé (montant, lien de paiement Stripe, prochain essai) et courriel au client ;
     * - au MAX_FAILED_PAYMENTS-ième essai, ou quand Stripe ne prévoit plus d'essai : résilié tout de suite, courriel.
     *
     * @return array{status: string, detail?: string}
     */
    private function paymentFailed(Subscription $subscription, array $invoice, string $locale, string $host): array
    {
        $em = $this->emProvider->getEntityManager();
        if ($subscription->getStatus() === Subscription::STATUS_INCOMPLETE || ($invoice['billing_reason'] ?? null) === 'subscription_create') {
            return ['status' => 'processed', 'detail' => 'premier paiement refusé : abonnement incomplete, nouvel essai possible'];
        }
        $attempt = (int) ($invoice['attempt_count'] ?? 1);
        $next = is_numeric($invoice['next_payment_attempt'] ?? null) ? (new \DateTimeImmutable())->setTimestamp((int) $invoice['next_payment_attempt']) : null;
        $issue = [
            'amountDue' => (int) ($invoice['amount_due'] ?? $invoice['amount_remaining'] ?? 0), 'currency' => strtoupper((string) ($invoice['currency'] ?? 'cad')),
            'hostedInvoiceUrl' => $invoice['hosted_invoice_url'] ?? null, 'nextPaymentAttempt' => $next?->format(\DateTimeInterface::ATOM),
            'attemptCount' => $attempt, 'maxAttempts' => SubscriptionService::MAX_FAILED_PAYMENTS,
        ];
        $subscription->setStatus(Subscription::STATUS_PAST_DUE)->setPaymentIssue($issue);
        $em->flush();
        $amount = number_format($issue['amountDue'] / 100, 2, ',', ' ') . ' ' . $issue['currency'];

        if ($attempt >= SubscriptionService::MAX_FAILED_PAYMENTS || $next === null) {
            try {
                $this->service->cancel($subscription, false);
            } catch (SubscriptionException $e) {
                // Déjà résilié chez Stripe (réglage de relance du compte) : l'état local suffit
                $this->logger->warning('[Subscription] Résiliation après impayés : ' . $e->getMessage(), ['subscription' => $subscription->getId()]);
                $subscription->setStatus(Subscription::STATUS_CANCELED)->setCanceledAt($subscription->getCanceledAt() ?? new \DateTimeImmutable());
                $em->flush();
            }
            $this->mailer->notify($subscription, 'canceled_unpaid', $locale, $host, ['rows' => ['Montant impayé' => $amount, 'Essais de paiement' => (string) $attempt]]);

            return ['status' => 'processed', 'detail' => sprintf('résilié après %d essai(s) de paiement', $attempt)];
        }
        $this->mailer->notify($subscription, 'payment_failed', $locale, $host, [
            'rows' => ['Montant dû' => $amount, 'Essai' => sprintf('%d sur %d', $attempt, SubscriptionService::MAX_FAILED_PAYMENTS), 'Prochain essai' => $next->format('d/m/Y')],
            'payUrl' => $issue['hostedInvoiceUrl'],
        ]);

        return ['status' => 'processed', 'detail' => sprintf('past_due, essai %d sur %d', $attempt, SubscriptionService::MAX_FAILED_PAYMENTS)];
    }

    private static function metadata(array $object, string $key): ?string
    {
        foreach ([$object['metadata'] ?? null, $object['subscription_details']['metadata'] ?? null, $object['parent']['subscription_details']['metadata'] ?? null, $object['lines']['data'][0]['metadata'] ?? null] as $metadata) {
            if (is_array($metadata) && isset($metadata[$key]) && $metadata[$key] !== '') {
                return (string) $metadata[$key];
            }
        }

        return null;
    }

    private static function invoiceSubscriptionId(array $invoice): string
    {
        $candidate = $invoice['subscription'] ?? $invoice['parent']['subscription_details']['subscription'] ?? $invoice['lines']['data'][0]['subscription'] ?? null;
        if (is_array($candidate)) {
            $candidate = $candidate['id'] ?? null;
        }

        return is_string($candidate) ? $candidate : '';
    }
}
