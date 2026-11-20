<?php

namespace App\Controller\StripeController;

use App\MemoiresVivantes\UseCase\Payment\HandleBookCheckoutCompletedUseCase;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\SubscriptionUseCase\HandleSubscriptionWebhookUseCase;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Psr\Log\LoggerInterface;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class StripeWebhookController extends AbstractController
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionManager $connectionManager,
        private readonly HandleBookCheckoutCompletedUseCase $handleBookCheckoutCompletedUseCase,
        private readonly LoggerInterface $logger,
        private readonly HandleSubscriptionWebhookUseCase $subscriptionWebhookUseCase,
        private readonly \App\UseCase\CheckoutUseCase\FinalizeCheckoutOrderUseCase $finalizeCheckout,
        private readonly \App\MemoiresVivantes\Services\PrintPaymentService $printPayments,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment = 'prod',
        private readonly ?\App\Incident\Services\PlatformIncidentService $incidents = null, // lot WEBHOOK-SIG : refus comptés
        #[Autowire(service: 'cache.state')] private readonly ?\Psr\Cache\CacheItemPoolInterface $state = null,
    ) {}

    /**
     * Lot WEBHOOK-SIG : un refus compté dans les incidents de la plateforme AU PLUS une fois par minute et par motif (la route est publique :
     * un flot de requêtes non signées n'écrit pas dans la base maître à chaque fois, et le contexte n'est jamais celui de l'expéditeur).
     */
    private function countRefusal(string $place, string $message): void
    {
        try {
            if ($this->state !== null) {
                $item = $this->state->getItem('stripe_webhook_refusal_w_' . str_replace('.', '_', $place));
                if ($item->isHit()) {
                    return;
                }
                $this->state->save($item->set(1)->expiresAfter(60));
            }
            $this->incidents?->recordPlatformIssue(null, $place, 'warning', $message);
        } catch (\Throwable) {
            // un compteur indisponible ne change rien au refus
        }
    }

    /**
     * Endpoint Webhook Stripe pour recevoir les notifications en temps réel (ex: checkout.session.completed).
     */
    #[Route('/api/stripe/webhook', name: 'api_stripe_webhook', methods: ['POST'])]
    #[Route('/stripe/webhook', name: 'stripe_webhook', methods: ['POST'])]
    public function handleWebhook(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = $request->headers->get('stripe-signature');
        $secrets = array_values(array_unique(array_filter([
            $_ENV['STRIPE_WEBHOOK_SECRET'] ?? null,
            $_SERVER['STRIPE_WEBHOOK_SECRET'] ?? null,
            $_ENV['STRIPE_CONNECT_WEBHOOK_SECRET'] ?? null,
            $_SERVER['STRIPE_CONNECT_WEBHOOK_SECRET'] ?? null,
            $_ENV['STRIPE_DIRECT_WEBHOOK_SECRET'] ?? null,
            $_SERVER['STRIPE_DIRECT_WEBHOOK_SECRET'] ?? null,
            getenv('STRIPE_WEBHOOK_SECRET') ?: null,
        ])));

        $event = null;

        if (!empty($sigHeader) && !empty($secrets)) {
            $lastException = null;
            foreach ($secrets as $secret) {
                try {
                    $event = Webhook::constructEvent($payload, $sigHeader, $secret);
                    break;
                } catch (SignatureVerificationException $e) {
                    $lastException = $e;
                } catch (\UnexpectedValueException $e) {
                    $lastException = $e;
                    break;
                }
            }
            if (!$event && $lastException) {
                $this->logger->error('[StripeWebhook] Signature invalide : ' . $lastException->getMessage(), ['ip' => $request->getClientIp(), 'at' => gmdate('c')]);
                $this->countRefusal('stripe_webhook.invalid_signature', 'Webhook Stripe refusé : signature invalide'); // lot WEBHOOK-SIG

                return $this->json(['error' => 'Signature invalide', 'code' => 'SIGNATURE_INVALID'], Response::HTTP_BAD_REQUEST);
            }
        } elseif ($this->environment === 'test') {
            // Environnement des ESSAIS seulement : un événement non signé est accepté (les essais n'ont pas le secret de Stripe)
            $event = json_decode($payload, true);
        } else {
            // Lot WEBHOOK-SIG (20/11/2026, faille de sécurité) : hors essais, un événement sans signature vérifiée est REFUSÉ avant tout
            // traitement, sur TOUS les chemins (un achat de livre non signé passait pour payé). Journal (type, adresse, heure) et un
            // incident de la plateforme dont le compteur monte à chaque refus (aucun courriel par refus : le résumé du jour le cite).
            $type = json_decode($payload, true)['type'] ?? null;
            $type = is_string($type) ? mb_substr(preg_replace('/[^a-z0-9._]/i', '', $type), 0, 60) : null;
            $this->logger->warning('[StripeWebhook] Événement refusé : signature absente', ['type' => $type, 'ip' => $request->getClientIp(), 'at' => gmdate('c'), 'secrets' => $secrets !== []]);
            $this->countRefusal('stripe_webhook.unsigned', 'Webhook Stripe refusé : signature absente');

            return $this->json(['error' => 'Signature requise', 'code' => 'SIGNATURE_REQUIRED'], Response::HTTP_BAD_REQUEST);
        }

        if (!$event) {
            return $this->json(['error' => 'Impossible de décoder l\'événement'], Response::HTTP_BAD_REQUEST);
        }

        $eventType = is_object($event) ? $event->type : ($event['type'] ?? null);
        // Un objet du SDK se convertit par toArray() : le transtypage (array) ne donnait que ses propriétés internes
        $eventData = is_object($event) ? $event->data->object->toArray() : ($event['data']['object'] ?? []);

        // Conversion récursive des objets Stripe en array si nécessaire
        if (is_object($eventData)) {
            $eventData = json_decode(json_encode($eventData), true);
        }

        $this->logger->info(sprintf('[StripeWebhook] Événement reçu : %s', $eventType));


        // Abonnements (Stripe Billing du compte connecté, boutique réglable 09/10/2026) : signature exigée (hors tests)
        if (in_array($eventType, HandleSubscriptionWebhookUseCase::TYPES, true)) {
            if (!is_object($event) && $this->environment !== 'test') {
                $this->logger->error('[StripeWebhook] Évènement d\'abonnement sans signature vérifiée : ignoré');

                return $this->json(['error' => 'Signature requise'], Response::HTTP_BAD_REQUEST);
            }

            // lot PROVISION-3 : identifiant, date et compte de l'événement pour le journal (un événement déjà traité n'est pas refait) et l'ordre
            $eventId = is_object($event) ? ($event->id ?? null) : ($event['id'] ?? null);
            $created = is_object($event) ? ($event->created ?? null) : ($event['created'] ?? null);
            $account = is_object($event) ? ($event->account ?? null) : ($event['account'] ?? null);

            $result = $this->subscriptionWebhookUseCase->handle($eventType, $eventData, $request->getSchemeAndHttpHost(),
                is_string($eventId) && $eventId !== '' ? $eventId : null, is_numeric($created) ? (int) $created : null, is_string($account) ? $account : null);

            return $this->json($result, ($result['status'] ?? '') === 'in_progress' ? Response::HTTP_CONFLICT : Response::HTTP_OK);
        }

        // Paiement d'un panier autorisé (capture manuelle : amount_capturable_updated ; capture automatique : succeeded) :
        // la commande est créée si le navigateur ne l'a pas fait (onglet fermé juste après le paiement), 09/10/2026
        // Terminal de paiement de la caisse (C4, 23/10/2026) : ses PaymentIntent portent metadata.pos_terminal = "1" et son état est relu
        // par le POS ; jamais traités comme un paiement de la boutique, un abonnement ou un paiement Mémoires Vivantes
        if (!empty($eventData['metadata']['pos_terminal']) || str_starts_with((string) $eventType, 'terminal.')) {
            return $this->json(['status' => 'ignored', 'detail' => 'paiement par terminal de caisse (suivi par le POS)', 'type' => $eventType]);
        }
        if (in_array($eventType, ['payment_intent.amount_capturable_updated', 'payment_intent.succeeded'], true)) {
            if (!is_object($event) && $this->environment !== 'test') {
                $this->logger->error('[StripeWebhook] Paiement sans signature vérifiée : ignoré');

                return $this->json(['error' => 'Signature requise'], Response::HTTP_BAD_REQUEST);
            }
            $tenantCode = $eventData['metadata']['tenant_code'] ?? $eventData['metadata']['store_code'] ?? null;
            $tenant = is_string($tenantCode) ? $this->connectionManager->findTenantByCode($tenantCode) : null;
            if (!$tenant || empty($tenant['dbname']) || !is_string($eventData['id'] ?? null)) {
                return $this->json(['status' => 'ignored', 'detail' => 'paiement sans site connu (hors boutique)']);
            }
            $this->emProvider->switchTenant($tenant['dbname'], $tenantCode);
            // Impression Living Memory payée depuis l'application (17/10/2026) : complétée côté serveur si l'application
            // n'a pas appelé print/order (téléphone fermé juste après le paiement) ; jamais traitée comme un panier
            if (!empty($eventData['metadata']['memoires_print'])) {
                $result = $this->printPayments->completeFromWebhook($eventData['id'], $request->getSchemeAndHttpHost());
                $this->logger->info(sprintf('[StripeWebhook] impression %s : %s (%s)', $eventData['id'], $result['status'], $result['detail']));

                return $this->json($result);
            }
            $result = $this->finalizeCheckout->fromWebhook($eventData['id']);
            $this->logger->info(sprintf('[StripeWebhook] %s %s : %s (%s)', $eventType, $eventData['id'], $result['status'], $result['detail']));

            return $this->json($result);
        }

        // Remboursement Stripe d'une impression commandée depuis l'application Living Memory (16/10/2026) : la commande
        // passe en « refunded » pour la console d'administration ; rien d'autre n'est fait (le livre reste imprimé)
        if ($eventType === 'charge.refunded' && !empty($eventData['metadata']['memoires_print']) && is_string($eventData['payment_intent'] ?? null)) {
            if (!is_object($event) && $this->environment !== 'test') {
                return $this->json(['error' => 'Signature requise'], Response::HTTP_BAD_REQUEST);
            }
            $tenantCode = $eventData['metadata']['tenant_code'] ?? null;
            $tenant = is_string($tenantCode) && $tenantCode !== '' ? $this->connectionManager->findTenantByCode($tenantCode) : null;
            if (!$tenant || empty($tenant['dbname'])) {
                return $this->json(['status' => 'ignored', 'detail' => 'impression sans site connu']);
            }
            $this->emProvider->switchTenant($tenant['dbname'], $tenantCode);
            $order = $this->printPayments->refundFromStripe($eventData['payment_intent'], ((int) ($eventData['amount_refunded'] ?? 0)) / 100);

            return $this->json(['status' => $order ? 'refunded' : 'ignored', 'detail' => $order ? 'commande ' . $order->getId()->toRfc4122() : 'intention inconnue']);
        }

        if ($eventType === 'checkout.session.completed') {
            $sessionId = $eventData['id'] ?? null;
            $metadata = $eventData['metadata'] ?? [];
            $tenantCode = $metadata['tenant_code'] ?? 'memoiresvivantes';

            $this->logger->info(sprintf(
                '[StripeWebhook] Traitement checkout.session.completed pour session %s (tenant: %s)',
                $sessionId,
                $tenantCode
            ));

            // Bascule vers la base de données du tenant concerné
            if ($tenantCode) {
                $tenant = $this->connectionManager->findTenantByCode($tenantCode);
                if ($tenant && !empty($tenant['dbname'])) {
                    $this->emProvider->switchTenant($tenant['dbname'], $tenantCode);
                }
            }

            if ($sessionId) {
                try {
                    $book = $this->handleBookCheckoutCompletedUseCase->execute($sessionId, $eventData);
                } catch (\App\MemoiresVivantes\Services\BookPaymentRetryLater) {
                    return $this->json(['status' => 'retry', 'detail' => 'session Stripe illisible pour le moment'], Response::HTTP_SERVICE_UNAVAILABLE); // Stripe réessaiera
                }
                if ($book) {
                    return $this->json([
                        'status' => 'success',
                        'message' => 'Livre mis à jour à PAYÉ avec succès.',
                        'book_id' => (string) $book->getId(),
                    ]);
                }
            }
        }

        return $this->json(['status' => 'ignored', 'type' => $eventType]);
    }
}
