<?php

namespace App\Controller\PlatformController;

use App\Services\Platform\OfferFulfillmentService;
use App\Services\TenantConnectionManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Événements de facturation externes (console de pilotage, lot 3 bis, 04/11/2026) : un vendeur extérieur à la boutique de la plateforme
 * (ou le super administrateur à la main) traduit un paiement en droits d'usage. POST { event: activated | renewed | unpaid | paused |
 * resumed | cancelled, tenant, offerCode, reference, periodEnd? (ISO 8601) } → { event, features, actions } ; 404 TENANT_NOT_FOUND |
 * OFFER_NOT_FOUND ; 422 VALIDATION_FAILED. Réservé au super administrateur (un jeton de service viendra avec le premier vendeur externe).
 * La boutique de la plateforme n'en a pas besoin : son branchement est interne (PlatformOfferFulfillmentSubscriber).
 */
#[Route('/api/platform/billing-events')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class PlatformBillingEventsController extends AbstractController
{
    public function __construct(private readonly OfferFulfillmentService $fulfillment, private readonly TenantConnectionManager $tenants)
    {
    }

    #[Route('', name: 'api_platform_billing_events', methods: ['POST'])]
    public function receive(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $data = is_array($data) ? $data : [];
        $errors = [];
        $event = $data['event'] ?? null;
        if (!is_string($event) || !in_array($event, OfferFulfillmentService::EVENTS, true)) {
            $errors[] = ['path' => 'event', 'message' => implode(', ', OfferFulfillmentService::EVENTS) . ' attendu'];
        }
        foreach (['tenant', 'offerCode', 'reference'] as $field) {
            if (!is_string($data[$field] ?? null) || trim($data[$field]) === '') {
                $errors[] = ['path' => $field, 'message' => 'texte non vide attendu'];
            }
        }
        $periodEnd = null;
        if (isset($data['periodEnd']) && $data['periodEnd'] !== null && $data['periodEnd'] !== '') {
            try {
                $periodEnd = new \DateTimeImmutable((string) $data['periodEnd']);
            } catch (\Throwable) {
                $errors[] = ['path' => 'periodEnd', 'message' => 'date ISO 8601 attendue'];
            }
        }
        if ($errors !== []) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => $errors[0]['path'] . ' : ' . $errors[0]['message'], 'errors' => $errors], 422);
        }
        $tenant = trim($data['tenant']);
        if (!is_array($this->tenants->findTenantByCode($tenant))) {
            return $this->json(['error' => 'TENANT_NOT_FOUND', 'code' => 'TENANT_NOT_FOUND', 'message' => 'site inconnu'], 404);
        }
        try {
            $result = $this->fulfillment->apply($event, $tenant, trim($data['offerCode']), mb_substr(trim($data['reference']), 0, 255), $periodEnd, $this->getUser()?->getUserIdentifier());
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'OFFER_NOT_FOUND', 'code' => 'OFFER_NOT_FOUND', 'message' => $e->getMessage()], 404);
        }

        return $this->json($result);
    }
}
