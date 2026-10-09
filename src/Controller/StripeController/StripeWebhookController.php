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
        #[Autowire('%kernel.environment%')]
        private readonly string $environment = 'prod'
    ) {}

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
                $this->logger->error('[StripeWebhook] Signature invalide : ' . $lastException->getMessage());
                return $this->json(['error' => 'Signature invalide'], Response::HTTP_BAD_REQUEST);
            }
        } else {
            // Mode fallback sans vérification de signature si aucun header ou secret
            $event = json_decode($payload, true);
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

            return $this->json($this->subscriptionWebhookUseCase->execute($eventType, $eventData, $request->getSchemeAndHttpHost()));
        }

        // Paiement d'un panier autorisé (capture manuelle : amount_capturable_updated ; capture automatique : succeeded) :
        // la commande est créée si le navigateur ne l'a pas fait (onglet fermé juste après le paiement), 09/10/2026
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
            $result = $this->finalizeCheckout->fromWebhook($eventData['id']);
            $this->logger->info(sprintf('[StripeWebhook] %s %s : %s (%s)', $eventType, $eventData['id'], $result['status'], $result['detail']));

            return $this->json($result);
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
                $book = $this->handleBookCheckoutCompletedUseCase->execute($sessionId, $eventData);
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
