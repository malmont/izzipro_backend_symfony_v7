<?php

namespace App\Controller\SiteFeaturesController;

use App\Services\Platform\ShippingBalanceRepository;
use App\Services\ShippingService\ShippingSetupService;
use App\Services\TenantConnectionManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Livraison vue par l'administrateur du site (lot 2 Livraison, 07/11/2026 ; `^/api/site/shipping` = ROLE_ADMIN) :
 * GET /api/site/shipping → { overview (réglages, transporteurs, compteurs, cheminement, niveau), balance: { balanceCents, currency },
 * charges: [...] } pour le panneau Commerce de l'éditeur et l'assistant.
 */
#[Route('/api/site/shipping')]
class SiteShippingController extends AbstractController
{
    public function __construct(private readonly ShippingSetupService $setup, private readonly ShippingBalanceRepository $balances, private readonly TenantConnectionManager $tenants)
    {
    }

    #[Route('', name: 'api_site_shipping', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $code = (string) $this->tenants->getCurrentTenantCode();

        return $this->json(['overview' => $this->setup->overview(), 'balance' => $this->balances->balance($code), 'charges' => $this->balances->charges($code, 20)]);
    }
}
