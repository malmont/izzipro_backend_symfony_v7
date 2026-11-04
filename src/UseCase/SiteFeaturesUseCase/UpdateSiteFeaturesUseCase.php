<?php

namespace App\UseCase\SiteFeaturesUseCase;

use App\Dto\BoutiqueSettingsInputDto;
use App\Entity\Entreprise;
use App\Services\BoutiqueSettingsService\BoutiqueSettingsException;
use App\Services\BoutiqueSettingsService\BoutiqueSettingsService;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\EntrepriseService\EntrepriseForbiddenException;
use App\Services\EntrepriseService\EntrepriseValidationException;
use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\FeatureAccess\SiteFeaturesValidationException;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\BoutiqueSettingsUseCase\UpdateBoutiqueSettingsUseCase;
use App\UseCase\EntrepriseUsecase\UpdateEntrepriseUseCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * PUT /api/site/features (console de pilotage, lot 2, 04/11/2026) : écrit les clés `site` du registre (et `module.*` pour le
 * super administrateur) là où elles vivent déjà, par les cas d'usage existants, donc avec leur validation et leur journal
 * des écritures : fiche Entreprise (UpdateEntrepriseUseCase : langue, fuseau, devise, prix TTC, activations) et réglages de la
 * boutique (UpdateBoutiqueSettingsUseCase : clés `commerce`). Une clé `platform` envoyée par un administrateur de site avec sa
 * valeur actuelle est ignorée ; différente → EntrepriseForbiddenException (403 SUPER_ADMIN_REQUIRED). Tout ou rien par cible.
 */
class UpdateSiteFeaturesUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly UpdateEntrepriseUseCase $entreprise,
        private readonly UpdateBoutiqueSettingsUseCase $boutique,
        private readonly BoutiqueSettingsService $boutiqueSettings,
        private readonly Security $security,
    ) {
    }

    /**
     * @param array<string, mixed> $values clé du registre => valeur
     * @throws SiteFeaturesValidationException clé inconnue, non modifiable ou valeur invalide (toutes les erreurs)
     * @throws EntrepriseForbiddenException clé `platform` modifiée par un administrateur de site
     * @throws EntrepriseValidationException|BoutiqueSettingsException valeur refusée par la cible
     */
    public function execute(array $values, string $host, string $locale = 'fr'): void
    {
        $superAdmin = $this->security->isGranted('ROLE_SUPER_ADMIN');
        $errors = [];
        $entrepriseData = [];
        $commerceData = [];
        foreach ($values as $key => $value) {
            if (!is_string($key) || !FeatureCatalog::exists($key)) {
                $errors[] = ['path' => (string) $key, 'message' => 'clé inconnue du registre'];
                continue;
            }
            $def = FeatureCatalog::get($key);
            $storage = FeatureCatalog::storage($key);
            if ($storage === null || $def['scope'] === FeatureCatalog::SCOPE_SERVICE) {
                $errors[] = ['path' => $key, 'message' => 'en lecture seule : se règle sur son écran'];
                continue;
            }
            if ($def['scope'] === FeatureCatalog::SCOPE_PLATFORM && !in_array($key, FeatureCatalog::PLATFORM_WRITABLE, true)) {
                $errors[] = ['path' => $key, 'message' => 'en lecture seule : les applications s\'activent sur la fiche Entreprise'];
                continue;
            }
            // clé platform par un administrateur de site : UpdateEntrepriseUseCase ignore une valeur inchangée et refuse une valeur différente
            [$target, $field] = $storage;
            if ($target === 'entreprise') {
                $entrepriseData[$field] = $value;
            } else {
                $commerceData[$field] = $value;
            }
        }
        if ($errors !== []) {
            throw new SiteFeaturesValidationException($errors);
        }
        if ($entrepriseData !== []) {
            $id = $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([], ['id' => 'ASC'])?->getId();
            if ($id === null) {
                throw new SiteFeaturesValidationException([['path' => 'site', 'message' => 'aucune fiche Entreprise sur ce site']]);
            }
            $this->entreprise->execute($id, $entrepriseData, $host, $locale);
        }
        if ($commerceData !== []) {
            $this->writeCommerce($commerceData);
        }
        unset($superAdmin);
    }

    /** @param array<string, mixed> $commerceData clés `commerce` des réglages de la boutique */
    private function writeCommerce(array $commerceData): void
    {
        // lecture seule ici : UpdateBoutiqueSettingsUseCase crée la ligne s'il n'en existe pas (un findOrCreate ici en créerait une seconde)
        $setting = $this->emProvider->getEntityManager()->getRepository(\App\Entity\BoutiqueSetting::class)->findOneBy([], ['id' => 'ASC']);
        $raw = $setting !== null ? $this->boutiqueSettings->getRawConfiguration($setting) : null;
        // configuration relue en objets (un {} vide resterait {} : jamais en tableau PHP, voir BoutiqueSettingsController::body)
        $configuration = is_string($raw) ? json_decode($raw, false) : null;
        if (!is_object($configuration)) {
            $configuration = new \stdClass();
        }
        if (!isset($configuration->commerce) || !is_object($configuration->commerce)) {
            $configuration->commerce = new \stdClass();
        }
        foreach ($commerceData as $field => $value) {
            if ($value === null) {
                unset($configuration->commerce->{$field});
            } else {
                $configuration->commerce->{$field} = $value;
            }
        }
        $this->boutique->execute(new BoutiqueSettingsInputDto($configuration, json_encode($configuration, ContentAuditRecorder::JSON_FLAGS | JSON_THROW_ON_ERROR)));
    }
}
