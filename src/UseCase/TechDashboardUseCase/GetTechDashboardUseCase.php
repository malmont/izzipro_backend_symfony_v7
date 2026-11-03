<?php

namespace App\UseCase\TechDashboardUseCase;

use App\Services\TechMetricsService\TechDashboardService;

/**
 * Page « Tableau de bord technique » (T2, 03/11/2026) : normalise les paramètres de la requête (fenêtre, site, tri) et rend le
 * modèle de la page. Lecture seule, base maître uniquement.
 */
class GetTechDashboardUseCase
{
    public function __construct(private readonly TechDashboardService $dashboard)
    {
    }

    public function execute(?string $window, ?string $site, ?string $sort): array
    {
        $window = isset(TechDashboardService::WINDOWS[(string) $window]) ? (string) $window : TechDashboardService::DEFAULT_WINDOW;
        $sort = isset(TechDashboardService::RESOURCES[(string) $sort]) ? (string) $sort : TechDashboardService::DEFAULT_SORT;
        $site = $site !== null && $site !== '' && preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $site) ? $site : null;

        return $this->dashboard->build($window, $site, $sort);
    }
}
