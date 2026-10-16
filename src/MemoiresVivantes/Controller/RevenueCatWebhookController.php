<?php

namespace App\MemoiresVivantes\Controller;

use App\MemoiresVivantes\UseCase\Payment\HandleRevenueCatEventUseCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Webhook RevenueCat (16/10/2026) : achats et remboursements des rédactions de chapitre. Route publique
 * (access_control `^/api/webhooks/`), protégée par l'en-tête Authorization que RevenueCat envoie tel que réglé
 * dans son tableau de bord (REVENUECAT_WEBHOOK_SECRET). Sans secret configuré : 503, rien n'est traité.
 */
class RevenueCatWebhookController extends AbstractController
{
    public function __construct(private readonly HandleRevenueCatEventUseCase $handle, private readonly LoggerInterface $logger, private readonly string $webhookSecret = '')
    {
    }

    #[Route('/api/webhooks/revenuecat', name: 'api_webhook_revenuecat', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $secret = trim($this->webhookSecret);
        if ($secret === '') {
            return $this->json(['error' => 'REVENUECAT_NOT_CONFIGURED', 'message' => 'Webhook RevenueCat non configuré'], 503);
        }
        $provided = trim((string) $request->headers->get('Authorization', ''));
        if (str_starts_with(strtolower($provided), 'bearer ')) {
            $provided = trim(substr($provided, 7));
        }
        if (!hash_equals($secret, $provided)) {
            return $this->json(['error' => 'UNAUTHORIZED'], 401);
        }
        $payload = json_decode($request->getContent(), true);
        $event = is_array($payload) ? ($payload['event'] ?? null) : null;
        if (!is_array($event)) {
            return $this->json(['error' => 'BAD_REQUEST', 'message' => 'Corps attendu : {"event": {...}}'], 400);
        }
        try {
            $result = $this->handle->execute($event);
        } catch (\Throwable $e) {
            $this->logger->error('Webhook RevenueCat : ' . $e->getMessage(), ['type' => $event['type'] ?? null]);

            return $this->json(['error' => 'PROCESSING_FAILED', 'message' => 'Événement non traité'], 500);
        }
        $this->logger->info('Webhook RevenueCat : ' . ($event['type'] ?? '?') . ' → ' . json_encode($result));

        return $this->json(['received' => true] + $result);
    }
}
