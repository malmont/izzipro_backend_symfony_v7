<?php

namespace App\UseCase\LandingConfigUseCase;

use App\Repository\LandingConfigSyncRepository;
use App\Services\LandingConfigService\FrontendConfigFetcher;
use App\Services\LandingConfigService\LandingConfigCompatibilityChecker;
use App\Services\LandingConfigService\LandingConfigException;
use App\Services\LandingConfigService\LandingConfigStore;
use App\Services\LandingPageSettingsService\ReglableCompositionValidator;

/**
 * POST /api/landingpage-config/sync : télécharge la configuration publiée sous FRONTEND_CONFIG_URL (jamais un contenu
 * envoyé par le client), vérifie les empreintes, contrôle toutes les compositions de tous les tenants et les modèles
 * du catalogue avec le nouveau schéma, puis active la version (l'ancienne est conservée pour un retour arrière).
 * Une seule composition refusée : 409, rien n'est activé.
 */
class SyncLandingConfigUseCase
{
    public function __construct(
        private readonly LandingConfigStore $store,
        private readonly FrontendConfigFetcher $fetcher,
        private readonly LandingConfigCompatibilityChecker $checker,
        private readonly ReglableCompositionValidator $validator,
        private readonly LandingConfigSyncRepository $history
    ) {
    }

    /**
     * @throws LandingConfigException 409 compositions refusées ; 422 manifeste, empreinte ou fichier invalide ;
     *                                502 frontend injoignable ; 503 synchronisation non configurée
     */
    public function execute(string $author): array
    {
        return $this->store->locked(function () use ($author) {
            $version = null;
            try {
                $manifest = $this->fetcher->manifest();
                $version = $manifest['version'];
                $active = $this->store->describe($this->store->activeId());
                if ($manifest['version'] === $active['version'] && $manifest['files'] == $active['files']) {
                    $this->history->add('sync', $version, $author, 'up_to_date');

                    return ['result' => 'up_to_date', 'active' => $active];
                }

                $contents = $this->fetcher->files($manifest);
                $this->checkContents($contents);
                $id = $this->store->install($version, $contents);
                if ($id === $this->store->activeId()) {
                    $this->history->add('sync', $version, $author, 'up_to_date');

                    return ['result' => 'up_to_date', 'active' => $this->store->describe($id)];
                }

                $report = $this->checker->check($this->store->versionPath($id, LandingConfigStore::SCHEMA), $this->store->versionPath($id, LandingConfigStore::CATALOGUE));
                if ($report['refusedCount'] > 0) {
                    $this->store->uninstall($id);
                    throw new LandingConfigException(409, 'Compositions refusées', sprintf(
                        '%d erreur(s) : des compositions enregistrées ou des modèles du catalogue seraient refusés par la version %s. Rien n\'a été activé.',
                        $report['refusedCount'], $version
                    ), $report['refused'], ['refusedCount' => $report['refusedCount'], 'checked' => $report['checked'], 'version' => $version]);
                }

                $this->store->activate($id);
                $this->history->add('sync', $version, $author, 'activated', ['checked' => $report['checked'], 'skipped' => $report['skipped']]);

                return [
                    'result' => 'activated',
                    'active' => $this->store->describe($id),
                    'previous' => $this->store->describe((string) $this->store->previousId()),
                    'checked' => $report['checked'],
                    'skipped' => $report['skipped'],
                ];
            } catch (LandingConfigException $e) {
                $this->history->add('sync', $version, $author, match ($e->getStatusCode()) {
                    409 => 'rejected_compositions',
                    422 => $e->getError() === 'Empreinte invalide' ? 'rejected_hash' : 'rejected_file',
                    default => 'error',
                }, ['message' => $e->getMessage(), 'errors' => array_slice($e->getErrors(), 0, 5)]);
                throw $e;
            }
        });
    }

    /** Schéma : JSON Schema lisible par le validateur ; catalogue : JSON avec des familles */
    private function checkContents(array $contents): void
    {
        $errors = [];
        $schema = json_decode($contents[LandingConfigStore::SCHEMA]);
        if (!is_object($schema)) {
            $errors[] = ['path' => LandingConfigStore::SCHEMA, 'message' => 'JSON Schema (objet) attendu'];
        } else {
            $tmp = tempnam(sys_get_temp_dir(), 'schema');
            file_put_contents($tmp, $contents[LandingConfigStore::SCHEMA]);
            try {
                $this->validator->withSchemaFile($tmp)->validateComposition(new \stdClass());
            } catch (\Throwable $e) {
                $errors[] = ['path' => LandingConfigStore::SCHEMA, 'message' => 'schéma inutilisable : ' . $e->getMessage()];
            } finally {
                unlink($tmp);
            }
        }
        $catalogue = json_decode($contents[LandingConfigStore::CATALOGUE]);
        if (!is_array($catalogue->families ?? null) || $catalogue->families === []) {
            $errors[] = ['path' => LandingConfigStore::CATALOGUE, 'message' => 'catalogue JSON avec une liste « families » attendu'];
        }
        if ($errors) {
            throw new LandingConfigException(422, 'Fichier invalide', 'Un fichier publié par le frontend est inutilisable : rien n\'a été activé.', $errors);
        }
    }
}
