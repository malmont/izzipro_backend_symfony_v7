<?php

namespace App\Controller\PlatformController;

use App\Services\Platform\PlatformOfferRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Offres de la plateforme et leurs prix (console de pilotage, lot 3 bis, 04/11/2026), super administrateur : GET → { offers } (toutes,
 * actives ou non) ; PUT /{code} { labelFr?, labelEn?, features?, monthlyPriceCents?, yearlyPriceCents?, currency?, trialDays?, isActive?,
 * position? } crée ou modifie → { offer } ; 422 VALIDATION_FAILED. Catalogue public : GET /api/offers (PublicOffersController).
 */
#[Route('/api/platform/offers')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class PlatformOffersController extends AbstractController
{
    public function __construct(private readonly PlatformOfferRepository $offers)
    {
    }

    #[Route('', name: 'api_platform_offers_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(['offers' => $this->offers->all(false)]);
    }

    #[Route('/{code}', name: 'api_platform_offers_put', methods: ['PUT'], requirements: ['code' => '[a-z0-9_]+'])]
    public function put(string $code, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => 'corps JSON attendu', 'errors' => [['path' => '', 'message' => 'corps JSON attendu']]], 422);
        }
        $errors = $this->offers->upsert($code, $data, $this->getUser()?->getUserIdentifier());
        if ($errors !== []) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => $errors[0]['path'] . ' : ' . $errors[0]['message'], 'errors' => $errors], 422);
        }

        return $this->json(['offer' => $this->offers->find($code)]);
    }
}
