<?php

namespace App\Gestion\Services;

use App\Gestion\Services\Document\GestionDocumentService;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\SiteAssistantService\SiteAssistantService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;

/**
 * Unités IA de l'application de gestion (lot MOD, 11/11/2026, `docs/gestion-mise-a-niveau.md` § MOD) : une seule lecture pour l'écran. La
 * gestion consomme les crédits mensuels du site (même réserve que l'assistant et les fiches produit, LandingAiQuotaService), 1 crédit par
 * appel (coûts rendus pour suivre un changement de prix) ; palier IA du site (ai.help / ai.full, à part du module) ; plafonds du jour,
 * vérifiés avant les crédits (un refus faute de crédits ou de palier ne compte pas dans le plafond).
 */
class GestionAiUsageService
{
    public function __construct(
        private readonly LandingAiQuotaService $quota,
        private readonly SiteAssistantService $assistant,
        private readonly TenantConnectionManager $tenants,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PeriodCalendar $calendar,
        private readonly GestionAdviceService $advices,
    ) {
    }

    /** @return array{tier: string, credits: array<string, mixed>, costs: array<string, int>, limits: array<string, int>, today: array<string, int>} */
    public function usage(bool $superAdmin): array
    {
        $today = $this->calendar->today();
        $comments = (int) $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM gestion_simulation_comment WHERE store_day = ?', [$today->format('Y-m-d')]);

        return [
            'tier' => $this->assistant->tier((string) $this->tenants->getCurrentTenantCode(), $superAdmin),
            'credits' => $this->quota->credits(),
            'costs' => ['advice' => AdviceWriter::COST, 'comment' => GestionSimulationCommentService::COST, 'document' => GestionDocumentService::COST],
            'limits' => ['commentsPerDay' => GestionSimulationCommentService::PER_DAY, 'adviceRefreshPerDay' => GestionAdviceService::MANUAL_PER_DAY],
            'today' => ['comments' => $comments, 'adviceRefresh' => $this->advices->manualToday($today)],
        ];
    }
}
