<?php

namespace App\UseCase\LandingConfigUseCase;

use App\Repository\LandingConfigSyncRepository;
use App\Services\LandingConfigService\LandingConfigCompatibilityChecker;
use App\Services\LandingConfigService\LandingConfigException;
use App\Services\LandingConfigService\LandingConfigStore;

/**
 * POST /api/landingpage-config/rollback : revient à la version précédente (qui devient la version à restaurer si l'on
 * revient encore en arrière). Même contrôle qu'une synchronisation : refusé si une composition enregistrée depuis
 * ne passerait plus l'ancien schéma.
 */
class RollbackLandingConfigUseCase
{
    public function __construct(
        private readonly LandingConfigStore $store,
        private readonly LandingConfigCompatibilityChecker $checker,
        private readonly LandingConfigSyncRepository $history
    ) {
    }

    /**
     * @throws LandingConfigException 409 pas de version précédente ou compositions refusées
     */
    public function execute(string $author): array
    {
        return $this->store->locked(function () use ($author) {
            $previous = $this->store->previousId();
            if ($previous === null) {
                throw new LandingConfigException(409, 'Aucune version précédente', 'Aucune version précédente n\'est conservée.');
            }
            $target = $this->store->describe($previous);

            $report = $this->checker->check($this->store->versionPath($previous, LandingConfigStore::SCHEMA), $this->store->versionPath($previous, LandingConfigStore::CATALOGUE));
            if ($report['refusedCount'] > 0) {
                $this->history->add('rollback', $target['version'], $author, 'rejected_compositions', ['refusedCount' => $report['refusedCount'], 'errors' => array_slice($report['refused'], 0, 5)]);
                throw new LandingConfigException(409, 'Compositions refusées', sprintf(
                    '%d erreur(s) : des compositions enregistrées seraient refusées par la version %s. Retour arrière annulé.',
                    $report['refusedCount'], $target['version']
                ), $report['refused'], ['refusedCount' => $report['refusedCount'], 'checked' => $report['checked'], 'version' => $target['version']]);
            }

            $this->store->activate($previous);
            $this->history->add('rollback', $target['version'], $author, 'rolled_back');

            return ['result' => 'rolled_back', 'active' => $target, 'previous' => $this->store->describe((string) $this->store->previousId())];
        });
    }
}
