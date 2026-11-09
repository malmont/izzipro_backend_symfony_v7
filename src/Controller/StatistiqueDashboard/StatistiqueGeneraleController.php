<?php

namespace App\Controller\StatistiqueDashboard;

use App\UseCase\StatistiqueUseCase\SalesStatisticsUseCase\GetAverageOrderValueStatisticsUseCase;
use App\UseCase\StatistiqueUseCase\SalesStatisticsUseCase\GetOrderCountStatisticsUseCase;
use App\UseCase\StatistiqueUseCase\SalesStatisticsUseCase\GetRevenueStatisticsUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Statistiques de ventes de l'application de gestion (chiffre d'affaires, nombre de commandes, panier moyen), par source
 * de commande (`pos`, `ecommerce`, `mobile_app`, sinon toutes). Lot G3 (09/11/2026) : calcul par `SalesStatisticsService`
 * (fuseau du magasin, une requête agrégée par série, montants en cents, mois en numéro) ; jusque-là 25 use cases faisaient
 * une requête par point en heure serveur. Clés JSON inchangées ; `currency` ajoutée par GestionCurrencyResponseSubscriber.
 */
class StatistiqueGeneraleController extends AbstractController
{
    private const SOURCES = ['pos' => 2, 'ecommerce' => 1, 'mobile_app' => 3];

    public function __construct(
        private readonly GetRevenueStatisticsUseCase $revenue,
        private readonly GetOrderCountStatisticsUseCase $orderCount,
        private readonly GetAverageOrderValueStatisticsUseCase $averageOrderValue,
    ) {
    }

    #[Route('/api/statistiques/chiffre-affaires/{source}', name: 'statistiques_chiffre_affaires', methods: ['GET'])]
    public function getChiffreAffaires(Request $request, ?string $source = null): JsonResponse
    {
        $orderSource = self::SOURCES[$source] ?? null;
        $startDateParam = $request->query->get('startDate');
        $endDateParam = $request->query->get('endDate');
        if ($startDateParam && $endDateParam) {
            try {
                $startDate = new \DateTimeImmutable((string) $startDateParam);
                $endDate = new \DateTimeImmutable((string) $endDateParam);
            } catch (\Exception) {
                return $this->json(['error' => 'INVALID_DATE', 'code' => 'INVALID_DATE', 'message' => 'Format de date invalide'], JsonResponse::HTTP_BAD_REQUEST);
            }

            return $this->json([
                'startDate' => $startDate->format('Y-m-d'),
                'endDate' => $endDate->format('Y-m-d'),
                'revenue' => $this->revenue->customInterval($startDate, $endDate, $orderSource),
            ]);
        }

        return $this->json($this->revenue->execute($orderSource));
    }

    #[Route('/api/statistiques/nombre-commandes/{source}', name: 'statistiques_nombre_commandes', methods: ['GET'], defaults: ['source' => null])]
    public function getOrderStatistics(?string $source = null): JsonResponse
    {
        return $this->json($this->orderCount->execute(self::SOURCES[$source] ?? null));
    }

    #[Route('/api/statistiques/panier-moyen/{source}', name: 'statistiques_panier_moyen', methods: ['GET'])]
    public function getAverageOrderValueStatistics(?string $source = null): JsonResponse
    {
        return $this->json($this->averageOrderValue->execute(self::SOURCES[$source] ?? null));
    }
}
