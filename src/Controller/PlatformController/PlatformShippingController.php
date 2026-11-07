<?php

namespace App\Controller\PlatformController;

use App\Services\Platform\PlatformShippingService;
use App\Services\Platform\ShippingBalanceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Étiquettes par la plateforme, côté super administrateur (lot 2 Livraison, 07/11/2026 ; `^/api/platform` = ROLE_SUPER_ADMIN) :
 * GET /api/platform/shipping → { settings: { feeBp, feeFixedCents, carriers }, balances: [...] } ;
 * PUT /api/platform/shipping/settings { feeBp?, feeFixedCents?, carriers?: [{ id, carrier, label }] } ;
 * POST /api/platform/shipping/balances/{code}/credit { amountCents, note? } → { balanceCents, currency } ;
 * GET /api/platform/shipping/balances/{code} → { balance, events, charges }. 422 VALIDATION_FAILED.
 */
#[Route('/api/platform/shipping')]
class PlatformShippingController extends AbstractController
{
    public function __construct(private readonly PlatformShippingService $platform, private readonly ShippingBalanceRepository $balances)
    {
    }

    #[Route('', name: 'api_platform_shipping', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->json(['settings' => $this->platform->settings(), 'balances' => $this->balances->all()]);
    }

    #[Route('/settings', name: 'api_platform_shipping_settings', methods: ['PUT'])]
    public function settings(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $data = is_array($data) ? $data : [];
        $by = $this->getUser()?->getUserIdentifier();
        try {
            if (array_key_exists('feeBp', $data) || array_key_exists('feeFixedCents', $data)) {
                $current = $this->platform->settings();
                $this->platform->setFees((int) ($data['feeBp'] ?? $current['feeBp']), (int) ($data['feeFixedCents'] ?? $current['feeFixedCents']), $by);
            }
            if (array_key_exists('carriers', $data)) {
                if (!is_array($data['carriers'])) {
                    throw new \InvalidArgumentException('carriers : liste attendue');
                }
                $this->platform->setCarriers($data['carriers'], $by);
            }
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => $e->getMessage(), 'errors' => [['path' => 'settings', 'message' => $e->getMessage()]]], 422);
        }

        return $this->json(['settings' => $this->platform->settings()]);
    }

    #[Route('/balances/{code}', name: 'api_platform_shipping_balance', methods: ['GET'], requirements: ['code' => '[a-z0-9_-]+'])]
    public function balance(string $code): JsonResponse
    {
        return $this->json(['balance' => $this->balances->balance($code), 'events' => $this->balances->events($code), 'charges' => $this->balances->charges($code)]);
    }

    #[Route('/balances/{code}/credit', name: 'api_platform_shipping_credit', methods: ['POST'], requirements: ['code' => '[a-z0-9_-]+'])]
    public function credit(string $code, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $data = is_array($data) ? $data : [];
        $amount = (int) ($data['amountCents'] ?? 0);
        if ($amount <= 0 || $amount > 1000000) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => 'amountCents : entier entre 1 et 1 000 000 attendu', 'errors' => [['path' => 'amountCents', 'message' => 'entier entre 1 et 1 000 000 attendu']]], 422);
        }
        try {
            $result = $this->platform->credit($code, $amount, isset($data['note']) ? (string) $data['note'] : null, $this->getUser()?->getUserIdentifier());
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => $e->getMessage(), 'errors' => [['path' => 'code', 'message' => $e->getMessage()]]], 422);
        }

        return $this->json($result);
    }
}
