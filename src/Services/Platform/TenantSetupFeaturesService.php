<?php

namespace App\Services\Platform;

use App\Dto\BoutiqueSettingsInputDto;
use App\Entity\Entreprise;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\BoutiqueSettingsUseCase\UpdateBoutiqueSettingsUseCase;
use Psr\Log\LoggerInterface;

/**
 * Étape « Votre activité » du formulaire de création d'un site (console de pilotage, lot 5, 04/11/2026), appliquée après la fiche
 * Entreprise, sur la base du nouveau site (déjà courante) : langue, fuseau, devise ; modes de vente acceptés (réglages de la boutique) ;
 * pour chaque fonctionnalité demandée, un essai gratuit selon la politique de la plateforme (courriel de bienvenue au contact du site),
 * sinon une demande en attente dans la console « Plateforme » ; puis projection des droits sur la fiche (les modules non demandés sont
 * désactivés). Tout échec est journalisé et rendu, jamais bloquant : le site est créé.
 *
 * @return array{trials: list<string>, requested: list<string>, errors: list<string>}
 */
class TenantSetupFeaturesService
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly EntitlementService $entitlements,
        private readonly FeaturePolicyRepository $policies,
        private readonly FeatureRequestRepository $requests,
        private readonly UpdateBoutiqueSettingsUseCase $boutique,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string> $features clés gouvernées demandées
     * @param list<string> $salesModes parmi sale, rental, subscription (vide = les trois)
     * @return array{trials: list<string>, requested: list<string>, errors: list<string>}
     */
    public function apply(string $tenantCode, Entreprise $entreprise, ?string $siteLocale, ?string $timezone, ?string $currency, array $features, array $salesModes, ?string $by): array
    {
        $result = ['trials' => [], 'requested' => [], 'errors' => []];
        $em = $this->emProvider->getEntityManager();
        try {
            $entreprise->setLocale($siteLocale)->setPosTimezone($timezone);
            if ($currency !== null && preg_match('/^[A-Za-z]{3}$/', $currency)) {
                $entreprise->setCurrency(strtoupper($currency));
            }
            $em->flush();
        } catch (\Throwable $e) {
            $result['errors'][] = 'localisation : ' . $e->getMessage();
            $this->logger->error(sprintf('[Setup] %s : localisation non appliquée : %s', $tenantCode, $e->getMessage()));
        }
        $modes = array_values(array_intersect(['sale', 'rental', 'subscription'], $salesModes));
        if ($modes !== [] && count($modes) < 3) {
            try {
                $configuration = new \stdClass();
                $configuration->commerce = new \stdClass();
                $configuration->commerce->salesModes = $modes;
                $this->boutique->execute(new BoutiqueSettingsInputDto($configuration, json_encode($configuration, ContentAuditRecorder::JSON_FLAGS | JSON_THROW_ON_ERROR)));
            } catch (\Throwable $e) {
                $result['errors'][] = 'modes de vente : ' . $e->getMessage();
                $this->logger->error(sprintf('[Setup] %s : modes de vente non appliqués : %s', $tenantCode, $e->getMessage()));
            }
        }
        foreach (array_unique($features) as $feature) {
            if (!isset(FeatureCatalog::GOVERNED[$feature])) {
                continue;
            }
            try {
                if ($this->policies->get($feature)['trial_allowed']) {
                    $this->entitlements->trial($tenantCode, $feature, null, $by ?? 'création du site', 'création du site');
                    $result['trials'][] = $feature;
                } else {
                    $this->requests->insert($tenantCode, $feature, $by ?? 'création du site', 'Demandé à la création du site');
                    $result['requested'][] = $feature;
                }
            } catch (\Throwable $e) {
                $result['errors'][] = FeatureCatalog::label($feature) . ' : ' . $e->getMessage();
                $this->logger->error(sprintf('[Setup] %s : %s non accordé : %s', $tenantCode, $feature, $e->getMessage()));
            }
        }
        // les modules non demandés sont désactivés : la fiche reflète les droits, rien d'autre
        $this->entitlements->project($tenantCode);

        return $result;
    }
}
