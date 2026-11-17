<?php

namespace App\Controller\SiteFeaturesController;

use App\Entity\User;
use App\Security\Entry\EntryContext;
use App\Services\Platform\PlatformAccessJournal;
use App\Services\Storefront\TaxVerificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « J'ai vérifié mes taxes » (lot AG-3d, 18/11/2026) : `POST /api/site/taxes/verify` → 200 `{ taxes: { verified, at, by } }`. Un administrateur
 * DU site seulement : l'opérateur d'une agence, toute entrée par jeton et le compte de la plateforme sont refusés (403 `TAXES_VERIFY_FORBIDDEN`).
 * L'état se lit aussi dans la liste d'ouverture (`GET /api/site/storefront`, ligne `taxes`).
 */
#[Route('/api/site/taxes')]
#[IsGranted('ROLE_ADMIN')]
class SiteTaxesController extends AbstractController
{
    public function __construct(private readonly TaxVerificationService $taxes, private readonly EntryContext $entry)
    {
    }

    #[Route('/verify', name: 'api_site_taxes_verify', methods: ['POST'])]
    public function verify(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User || $this->entry->active() || PlatformAccessJournal::isPlatformAccount($user->getRoles())) {
            return $this->json(['error' => 'TAXES_VERIFY_FORBIDDEN', 'code' => 'TAXES_VERIFY_FORBIDDEN', 'message' => 'Seul un administrateur du site confirme la vérification de ses taxes.'], 403);
        }

        return $this->json(['taxes' => $this->taxes->verify($user)]);
    }
}
