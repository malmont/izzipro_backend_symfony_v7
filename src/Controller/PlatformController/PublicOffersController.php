<?php

namespace App\Controller\PlatformController;

use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\Platform\PlatformOfferRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/** Catalogue public des offres actives de la plateforme (lot 3 bis, 04/11/2026) : formulaire de création d'un site, bouton « demander », boutique de la plateforme */
class PublicOffersController extends AbstractController
{
    public function __construct(private readonly PlatformOfferRepository $offers)
    {
    }

    #[Route('/api/offers', name: 'api_offers', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $language = $request->query->get('locale') === 'en' ? 'en' : 'fr';
        $offers = [];
        foreach ($this->offers->all(true) as $offer) {
            $offers[] = [
                'code' => $offer['code'],
                'label' => $language === 'en' ? $offer['labelEn'] : $offer['labelFr'],
                'features' => array_map(fn (string $key) => ['key' => $key, 'label' => FeatureCatalog::label($key, $language)], $offer['features']),
                'monthlyPriceCents' => $offer['monthlyPriceCents'], 'yearlyPriceCents' => $offer['yearlyPriceCents'], 'currency' => $offer['currency'], 'trialDays' => $offer['trialDays'],
            ];
        }

        return $this->json(['offers' => $offers]);
    }
}
