<?php

namespace App\Services\SiteCharter;

use App\Dto\BoutiqueSettingsInputDto;
use App\Dto\LandingPageSettingsInputDto;
use App\Services\BoutiqueSettingsService\BoutiqueSettingsService;
use App\Services\LandingAiService\LandingAiCatalogue;
use App\UseCase\BoutiqueSettingsUseCase\UpdateBoutiqueSettingsUseCase;
use App\Services\LandingAiService\LandingAiJobService;
use App\Services\LandingPageSettingsService\LandingPageSettingsService;
use App\Services\LandingPageSettingsService\ReglableCompositionValidator;
use App\UseCase\LandingPageSettingsUseCase\UpdateLandingPageSettingsUseCase;

/**
 * Applique sur le site le résultat d'une composition « site complet » de l'assistant (08/11/2026) : l'accueil (sections, navbar, footer)
 * et chaque page composée deviennent les onglets de `landing_page_setting.configuration`, dans la forme que l'éditeur Next enregistre
 * (`tabs[].sections[] { id, uid, dataType, componentKey, componentTypeKey, reglableConfig }`, `navbar`, `footer`), après le même
 * contrôle que `PUT /api/landingpage-settings` (`ReglableCompositionValidator`). L'ancienne configuration reste dans le journal des
 * écritures (`content_audit_log`, restauration possible). La charte déduite par l'assistant est gardée si le site n'en avait pas.
 */
class SiteCompositionApplier
{
    public function __construct(
        private readonly LandingAiJobService $jobs,
        private readonly ReglableCompositionValidator $validator,
        private readonly LandingPageSettingsService $settings,
        private readonly UpdateLandingPageSettingsUseCase $update,
        private readonly SiteCharterService $charters,
        private readonly ?\App\Services\SiteContentService\SiteDemoContentService $demo = null, // contenu d'exemple (lot 3, 08/11/2026)
        private readonly ?BoutiqueSettingsService $boutique = null, // pages de la boutique (08/11/2026)
        private readonly ?UpdateBoutiqueSettingsUseCase $updateBoutique = null,
        private readonly ?LandingAiCatalogue $catalogue = null,
    ) {
    }

    /** Famille de section → ressource de contenu d'exemple (fiche liée par dataType) */
    private const RESOURCE_OF = ['Presentation' => 'presentations', 'PresentationGroup' => 'presentation-groups', 'BaniereStatique' => 'baniere-statiques', 'Baniere' => 'bannieres', 'Video' => 'videos', 'Marque' => 'marques', 'Reservation' => 'service-offers', 'Service' => 'service-offers', 'ExploreCards' => 'explore-cards'];

    /**
     * État d'une tâche de composition, pour l'écran d'attente.
     *
     * @return array{status: string, app: string, pages: list<array{title: string, status: string}>, error: ?string, warnings: list<string>, summary: ?string}
     */
    public function status(string $jobId): array
    {
        $job = $this->jobs->find($jobId);
        if ($job === null) {
            return ['status' => 'unknown', 'app' => 'landingpage', 'pages' => [], 'error' => 'Tâche introuvable ou expirée (les résultats sont gardés une heure).', 'warnings' => [], 'summary' => null];
        }
        $result = $job->getResult() !== null ? json_decode($job->getResult(), true) : null;
        $pages = [];
        foreach (is_array($result['pages'] ?? null) ? $result['pages'] : [] as $slug => $page) {
            $child = isset($page['jobId']) ? $this->jobs->find((string) $page['jobId']) : null;
            $pages[] = ['title' => (string) ($page['title'] ?? $this->systemTitle((string) $slug)), 'status' => $child?->getStatus() ?? (string) ($page['status'] ?? 'pending')];
        }
        $error = $job->getError() !== null ? (json_decode($job->getError(), true)['message'] ?? $job->getError()) : null;
        $app = is_array($result) && ($result['app'] ?? null) === 'boutique' ? 'boutique' : 'landingpage';
        $allDone = $job->getStatus() === 'done' && array_filter($pages, fn ($p) => $p['status'] !== 'done') === [];
        $anyFailed = $job->getStatus() === 'failed' || array_filter($pages, fn ($p) => $p['status'] === 'failed') !== [];

        return [
            'status' => $anyFailed ? 'failed' : ($allDone ? 'done' : 'running'), 'app' => $app, 'pages' => $pages, 'error' => $error,
            'warnings' => array_values(array_filter(array_map('strval', is_array($result['warnings'] ?? null) ? $result['warnings'] : []))), 'summary' => isset($result['summary']) ? (string) $result['summary'] : null,
        ];
    }

    /**
     * @return array{applied: bool, errors: list<string>, tabs: int, demoDeleted: int}
     */
    public function apply(string $jobId, string $locale = 'fr'): array
    {
        $job = $this->jobs->find($jobId);
        if ($job === null || $job->getStatus() !== 'done' || $job->getResult() === null) {
            return ['applied' => false, 'errors' => ['La composition n\'est pas terminée ou a expiré.'], 'tabs' => 0, 'demoDeleted' => 0];
        }
        $home = json_decode($job->getResult(), false);
        if (!is_object($home) || ($home->mode ?? null) !== 'site' || !is_array($home->sections ?? null) || !is_object($home->navbar ?? null) || !is_object($home->footer ?? null)) {
            return ['applied' => false, 'errors' => ['Résultat inattendu : ce n\'est pas une composition de site complet.'], 'tabs' => 0, 'demoDeleted' => 0];
        }
        if (($home->app ?? null) === 'boutique') {
            return $this->applyShop($jobId, $home);
        }
        $nextId = 1;
        $section = function (object $s) use (&$nextId): array {
            return ['id' => $nextId++, 'uid' => self::uuid(), 'dataType' => isset($s->dataType) && $s->dataType !== null ? (string) $s->dataType : null, 'componentKey' => (string) $s->componentKey, 'componentTypeKey' => 'typeReglable', 'reglableConfig' => $s->composition];
        };
        $tabs = [['id' => 1, 'title' => ['fr' => 'Accueil', 'en' => 'Home'], 'sections' => array_map($section, $home->sections)]];
        foreach (is_object($home->pages ?? null) ? get_object_vars($home->pages) : [] as $slug => $page) {
            $child = isset($page->jobId) ? $this->jobs->find((string) $page->jobId) : null;
            if ($child === null || $child->getStatus() !== 'done' || $child->getResult() === null) {
                return ['applied' => false, 'errors' => [sprintf('La page « %s » n\'est pas composée.', $page->title ?? $slug)], 'tabs' => 0, 'demoDeleted' => 0];
            }
            $result = json_decode($child->getResult(), false);
            $title = (string) ($page->title ?? $result->tab->title ?? $slug);
            $tabs[] = ['id' => count($tabs) + 1, 'title' => ['fr' => $title, 'en' => $title], 'sections' => array_map($section, is_array($result->sections ?? null) ? $result->sections : [])];
        }
        $current = json_decode((string) $this->settings->getRawConfiguration($this->settings->findOrCreateSettings()), true) ?: [];
        $configuration = array_merge($current, [
            'tabs' => $tabs,
            'navbar' => ['componentTypeKey' => 'typeReglable', 'reglableConfig' => $home->navbar],
            'footer' => ['componentTypeKey' => 'typeReglable', 'reglableConfig' => $home->footer],
        ]);
        $asObject = json_decode(json_encode($configuration, JSON_PRESERVE_ZERO_FRACTION), false);
        $errors = $this->validator->validateConfiguration($asObject);
        if ($errors !== []) {
            return ['applied' => false, 'errors' => array_map(fn ($e) => $e['path'] . ' : ' . $e['message'], array_slice($errors, 0, 10)), 'tabs' => 0, 'demoDeleted' => 0];
        }
        $this->update->execute(new LandingPageSettingsInputDto(json_decode(json_encode($asObject, JSON_PRESERVE_ZERO_FRACTION), true)));
        if (is_object($home->charter ?? null) && $this->charters->get()?->getCharter() === null) {
            $this->charters->save(['charter' => (array) $home->charter], 'assistant');
        }
        $this->charters->markComposed($jobId);

        // Contenu d'exemple (lot 3) : effacé sur demande, sauf les fiches que les pages composées affichent encore
        $demoDeleted = 0;
        if ($this->demo !== null && (bool) (($this->charters->get()?->getBrief() ?? [])['replaceDemo'] ?? false)) {
            $protect = [];
            foreach ($tabs as $tab) {
                foreach ($tab['sections'] as $section) {
                    $resource = self::RESOURCE_OF[$section['componentKey']] ?? null;
                    if ($resource !== null && $section['dataType'] !== null && ctype_digit((string) $section['dataType'])) {
                        $protect[$resource][] = (int) $section['dataType'];
                    }
                }
            }
            try {
                $demoDeleted = (int) array_sum($this->demo->delete($locale, \App\Services\SiteContentService\SiteContentSpec::resourcesFor(['landing']), $protect)['deleted']);
            } catch (\Throwable) {
                $demoDeleted = 0; // un refus (fiche utilisée) n'empêche pas la composition d'être appliquée
            }
        }

        return ['applied' => true, 'errors' => [], 'tabs' => count($tabs), 'demoDeleted' => $demoDeleted];
    }

    /**
     * Pages de la boutique (08/11/2026) : l'accueil composé remplace les sections du premier onglet sans `system`, chaque page système composée
     * remplace les sections de son onglet `system` (sans variante ; créé s'il manque, `sys-<clé>`), les autres onglets restent ; navbar et
     * footer remplacés ; la charte déduite est posée si la boutique n'en a pas. Même contrôle que `PUT /api/boutique-settings`.
     *
     * @return array{applied: bool, errors: list<string>, tabs: int, demoDeleted: int}
     */
    private function applyShop(string $jobId, object $home): array
    {
        if ($this->boutique === null || $this->updateBoutique === null) {
            return ['applied' => false, 'errors' => ['Pages de la boutique : service indisponible.'], 'tabs' => 0, 'demoDeleted' => 0];
        }
        $nextId = 1;
        $section = function (object $s) use (&$nextId): array {
            return ['id' => $nextId++, 'uid' => self::uuid(), 'dataType' => isset($s->dataType) && $s->dataType !== null ? (string) $s->dataType : null, 'componentKey' => (string) $s->componentKey, 'componentTypeKey' => 'typeReglable', 'reglableConfig' => $s->composition];
        };
        $current = json_decode((string) $this->boutique->getRawConfiguration($this->boutique->findOrCreateSettings()), true) ?: [];
        $tabs = is_array($current['tabs'] ?? null) ? array_values($current['tabs']) : [];
        $homeIndex = null;
        foreach ($tabs as $i => $tab) {
            if (empty($tab['system'])) {
                $homeIndex = $i;
                break;
            }
        }
        $homeTab = ['id' => 1, 'title' => ['fr' => 'Accueil', 'en' => 'Home'], 'sections' => array_map($section, $home->sections)];
        if ($homeIndex === null) {
            array_unshift($tabs, $homeTab);
        } else {
            $tabs[$homeIndex] = ['sections' => $homeTab['sections']] + $tabs[$homeIndex];
        }
        $composed = 1;
        foreach (is_object($home->pages ?? null) ? get_object_vars($home->pages) : [] as $key => $page) {
            $child = isset($page->jobId) ? $this->jobs->find((string) $page->jobId) : null;
            if ($child === null || $child->getStatus() !== 'done' || $child->getResult() === null) {
                return ['applied' => false, 'errors' => [sprintf('La page « %s » n\'est pas composée.', $this->systemTitle((string) $key))], 'tabs' => 0, 'demoDeleted' => 0];
            }
            $result = json_decode($child->getResult(), false);
            $sections = array_map($section, is_array($result->sections ?? null) ? $result->sections : []);
            $found = false;
            foreach ($tabs as $i => $tab) {
                if (($tab['system'] ?? null) === $key && empty($tab['variant'])) {
                    $tabs[$i] = ['sections' => $sections] + $tab;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $titles = $this->catalogue?->systemPages()[$key]['title'] ?? [];
                $tabs[] = ['id' => 'sys-' . $key, 'title' => ['fr' => (string) ($titles['fr'] ?? $key), 'en' => (string) ($titles['en'] ?? $key)], 'system' => $key, 'sections' => $sections, 'isVisible' => true];
            }
            $composed++;
        }
        $configuration = array_merge($current, [
            'tabs' => $tabs,
            'navbar' => ['componentTypeKey' => 'typeReglable', 'reglableConfig' => $home->navbar],
            'footer' => ['componentTypeKey' => 'typeReglable', 'reglableConfig' => $home->footer],
        ]);
        if (!isset($configuration['charter']) && is_object($home->charter ?? null)) {
            $configuration['charter'] = $home->charter;
        }
        $json = json_encode($configuration, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $asObject = json_decode((string) $json, false);
        try {
            $this->updateBoutique->execute(new BoutiqueSettingsInputDto($asObject, (string) $json));
        } catch (\Throwable $e) {
            return ['applied' => false, 'errors' => [$e->getMessage()], 'tabs' => 0, 'demoDeleted' => 0];
        }
        if (is_object($home->charter ?? null) && $this->charters->get()?->getCharter() === null) {
            $this->charters->save(['charter' => (array) $home->charter], 'assistant');
        }
        $this->charters->markShopComposed($jobId);

        return ['applied' => true, 'errors' => [], 'tabs' => $composed, 'demoDeleted' => 0];
    }

    private function systemTitle(string $key): string
    {
        return (string) ($this->catalogue?->systemPages()[$key]['title']['fr'] ?? $key);
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
