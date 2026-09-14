<?php

namespace App\Controller\StripeController;

use App\MemoiresVivantes\UseCase\Payment\HandleBookCheckoutCompletedUseCase;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
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
        private readonly LoggerInterface $logger
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
        $eventData = is_object($event) ? (array) $event->data->object : ($event['data']['object'] ?? []);

        // Conversion récursive des objets Stripe en array si nécessaire
        if (is_object($eventData)) {
            $eventData = json_decode(json_encode($eventData), true);
        }

        $this->logger->info(sprintf('[StripeWebhook] Événement reçu : %s', $eventType));

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
