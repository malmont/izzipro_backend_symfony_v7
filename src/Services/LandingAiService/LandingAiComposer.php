<?php

namespace App\Services\LandingAiService;

use App\Services\AnthropicApiException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Moteur de l'assistant : appel à Claude avec un outil de sortie, application et vérification de la proposition,
 * nouvel essai avec la liste des erreurs (3 essais au total). Ne lit que le site courant, n'écrit rien.
 */
final class LandingAiComposer
{
    public const MAX_ATTEMPTS = 3;
    public const CALL_TIMEOUT = 90.0;
    public const TOTAL_TIMEOUT = 180.0;
    /** Page et requêtes avec images (claude-opus-5-5, plusieurs sections) : une page de 4 sections prend déjà ~70 s */
    public const PAGE_CALL_TIMEOUT = 180.0;
    public const PAGE_TOTAL_TIMEOUT = 300.0;
    private const MIN_CALL_TIME = 5.0;
    /** Nouveaux essais d'un même appel sur une erreur passagère de l'API (429, 529, 5xx), dans le délai total */
    public const TRANSIENT_RETRIES = 2;
    private const MAX_RETRY_DELAY = 20;

    public function __construct(
        private readonly LandingAiClientInterface $client,
        private readonly LandingAiPromptBuilder $prompts,
        private readonly CompositionEditApplier $applier,
        private readonly LandingAiCompositionChecker $checker,
        private readonly LandingAiSiteContext $site,
        private readonly LandingAiDataSources $data,
        private readonly LoggerInterface $logger,
        private readonly LandingAiCatalogue $catalogue,
        private readonly LandingAiOutputRepair $repair,
        #[Autowire('%env(default:landing_ai.default_model_edit:LANDING_AI_MODEL_EDIT)%')]
        private readonly string $editModel,
        #[Autowire('%env(default:landing_ai.default_model_page:LANDING_AI_MODEL_PAGE)%')]
        private readonly string $pageModel,
        #[Autowire('%env(default::LANDING_AI_MODEL_IMAGES)%')]
        private readonly ?string $imagesModel = null
    ) {
    }

    public function editModel(): string
    {
        return $this->editModel;
    }

    /** Modèle du mode page (avec ou sans images) */
    public function pageModel(): string
    {
        return $this->pageModel;
    }

    /** Modèle des retouches et créations avec images (relecture visuelle comprise) : celui du mode page par défaut */
    public function imagesModel(): string
    {
        return trim((string) $this->imagesModel) !== '' ? trim($this->imagesModel) : $this->pageModel;
    }

    /**
     * Composition reçue pour une retouche : contrat, types de la famille, médias. À vérifier AVANT tout appel à l'IA :
     * une composition déjà invalide ferait échouer les 3 essais (appels payés, crédits libérés).
     *
     * @return list<array{path: string, message: string}>
     */
    public function editErrors(string $componentKey, object $composition): array
    {
        return $this->checker->check($composition, $componentKey, $this->site->allowedMedia([$composition]));
    }

    /**
     * Retouche : l'IA renvoie des opérations, appliquées par le serveur sur la composition actuelle.
     *
     * @param list<array> $media médias fournis par l'administrateur
     * @param list<array{mediaType: string, data: string}> $images captures ou charte (modèle des requêtes avec images)
     * @throws LandingAiException 502 (pas de composition valide, IA indisponible) ou 504 (délai)
     */
    public function edit(string $componentKey, object $composition, string $prompt, string $locale = 'fr', array $media = [], array $images = []): LandingAiEditResult
    {
        $stored = $this->site->storedCompositions();
        $allowedMedia = $this->site->allowedMedia([...$stored, $composition], $media, $prompt);
        $payload = $this->prompts->editPayload($this->model($images), $componentKey, $composition, $prompt, $locale, $media, $allowedMedia, $this->site->palette($stored), $images);

        return $this->run($payload, LandingAiPromptBuilder::EDIT_TOOL, $images !== [], function (object $input, LandingAiUsageStats $stats) use ($composition, $componentKey, $allowedMedia) {
            $operations = is_array($input->operations ?? null) ? $input->operations : [];
            $applied = $this->applier->apply($composition, $operations);
            $existing = [];
            if (!$applied['errors']) {
                $this->logRepairs([...$this->repair->repair($applied['composition']), ...$this->repair->dropRefusedKeys($applied['composition']), ...$this->repair->dropInventedBoundMedia($applied['composition'], $allowedMedia)]);
                $existing = array_values(array_filter(array_map(fn ($b) => is_object($b) && is_string($b->id ?? null) ? $b->id : null, is_array($composition->blocks ?? null) ? $composition->blocks : [])));
                $this->logBackgrounds($this->repair->transparentContainers($applied['composition'], $existing));
            }
            $errors = $applied['errors'] ?: [
                ...$this->checker->check($applied['composition'], $componentKey, $allowedMedia),
                ...$this->checker->missingBackgrounds($applied['composition'], $existing),
                ...$this->checker->sceneErrors($applied['composition'], $existing),
            ];
            if ($errors) {
                return [null, $errors];
            }

            return [new LandingAiEditResult($applied['composition'], $this->summary($input), $this->warnings($input->warnings ?? []), $applied['touched'], $operations, $stats), []];
        });
    }

    /**
     * Création : l'IA compose une section complète de la famille et choisit la donnée affichée (dataType) parmi
     * celles du site ; la donnée indiquée par l'éditeur sert de valeur par défaut.
     *
     * @param list<array> $media médias fournis par l'administrateur
     * @param list<array{mediaType: string, data: string}> $images captures ou charte (modèle des requêtes avec images)
     * @throws LandingAiException 502 ou 504
     */
    public function create(string $componentKey, string $prompt, string $locale = 'fr', array $media = [], ?string $defaultDataType = null, array $images = []): LandingAiCreateResult
    {
        $stored = $this->site->storedCompositions();
        $usesData = $this->data->familyUsesData($componentKey);
        $optional = $usesData && $this->data->dataOptional($componentKey);
        $available = $usesData ? $this->data->available($componentKey) : [];
        $availableIds = array_column($available, 'id');
        $allowedMedia = $this->site->allowedMedia([...$stored, ...$this->site->presetCompositions($componentKey)], $media, $prompt);
        $payload = $this->prompts->createPayload($this->model($images), $componentKey, $prompt, $locale, $media, $allowedMedia, $this->site->palette($stored), $usesData, $optional, $available, $defaultDataType, $images);
        $availableIds = [$componentKey => $availableIds];

        return $this->run($payload, LandingAiPromptBuilder::CREATE_TOOL, $images !== [], function (object $input, LandingAiUsageStats $stats) use ($componentKey, $allowedMedia, $availableIds) {
            [$section, $errors] = $this->checkSection($input, $componentKey, $allowedMedia, $availableIds, '');
            if ($errors) {
                return [null, $errors];
            }

            return [new LandingAiCreateResult($section['composition'], $section['dataType'], $this->summary($input), $this->warnings($input->warnings ?? []), $stats), []];
        });
    }

    /**
     * Page : plusieurs sections (familles choisies par l'IA, ou toutes de la famille $componentKey), avec d'éventuelles
     * captures d'écran ou une charte graphique. Chaque section est vérifiée comme une création.
     *
     * @param list<array> $media médias fournis par l'administrateur
     * @param list<array{mediaType: string, data: string}> $images
     * @throws LandingAiException 502 ou 504
     */
    public function page(string $prompt, string $locale = 'fr', array $media = [], array $images = [], ?string $componentKey = null): LandingAiPageResult
    {
        $stored = $this->site->storedCompositions();
        $families = $componentKey !== null ? [$componentKey] : $this->catalogue->componentKeys();
        $presets = array_merge(...array_map(fn ($key) => $this->site->presetCompositions($key), $families));
        $allowedMedia = $this->site->allowedMedia([...$stored, ...$presets], $media, $prompt);

        $siteData = [];
        $availableIds = [];
        foreach ($families as $family) {
            if ($this->data->familyUsesData($family)) {
                $siteData[$family] = ['optional' => $this->data->dataOptional($family), 'items' => $this->data->available($family)];
                $availableIds[$family] = array_column($siteData[$family]['items'], 'id');
            }
        }
        $payload = $this->prompts->pagePayload($this->pageModel, $componentKey, $prompt, $locale, $media, $allowedMedia, $this->site->palette($stored), $siteData, $images);

        return $this->run($payload, LandingAiPromptBuilder::PAGE_TOOL, true, function (object $input, LandingAiUsageStats $stats) use ($componentKey, $allowedMedia, $availableIds) {
            $sections = is_array($input->sections ?? null) ? $input->sections : [];
            if (!$sections || count($sections) > LandingAiPromptBuilder::PAGE_MAX_SECTIONS) {
                return [null, [['path' => 'sections', 'message' => sprintf('de 1 à %d sections attendues', LandingAiPromptBuilder::PAGE_MAX_SECTIONS)]]];
            }

            $valid = [];
            $errors = [];
            foreach ($sections as $i => $section) {
                $key = is_object($section) && is_string($section->componentKey ?? null) ? $section->componentKey : null;
                if ($key === null || !$this->catalogue->hasFamily($key) || ($componentKey !== null && $key !== $componentKey)) {
                    $errors[] = ['path' => "sections[$i].componentKey", 'message' => $componentKey !== null ? sprintf('famille imposée : %s', $componentKey) : 'famille du catalogue attendue'];
                    continue;
                }
                [$checked, $sectionErrors] = $this->checkSection($section, $key, $allowedMedia, $availableIds, "sections[$i].");
                $sectionErrors ? array_push($errors, ...$sectionErrors) : $valid[] = ['componentKey' => $key] + $checked;
            }
            $scenes = array_keys(array_filter($valid, fn ($s) => $this->checker->sceneCount($s['composition']) > 0));
            foreach (array_slice($scenes, 1) as $i) {
                $errors[] = ['path' => "sections[$i].composition", 'message' => 'une seule scène au défilement (layout « scroll ») par page : garde la première'];
            }
            if ($errors) {
                return [null, $errors];
            }

            return [new LandingAiPageResult($valid, $this->summary($input), $this->warnings($input->warnings ?? []), $stats), []];
        });
    }

    /**
     * Vérifie une section proposée ({dataType, composition}) : contrat, types de la famille, médias, dataType.
     *
     * @param array<string, list<string>> $availableIds identifiants de données par famille qui en utilise
     * @return array{0: array{dataType: ?string, composition: object}|null, 1: list<array{path: string, message: string}>}
     */
    private function checkSection(object $input, string $componentKey, array $allowedMedia, array $availableIds, string $prefix): array
    {
        $composition = $input->composition ?? null;
        if (!is_object($composition)) {
            return [null, [['path' => $prefix . 'composition', 'message' => 'composition complète attendue (objet schemaVersion 2)']]];
        }
        $this->logRepairs([...$this->repair->repair($composition), ...$this->repair->dropRefusedKeys($composition), ...$this->repair->dropInventedBoundMedia($composition, $allowedMedia)]);
        $this->logBackgrounds($this->repair->transparentContainers($composition));
        $dataType = $input->dataType ?? null;
        $dataType = is_int($dataType) || is_string($dataType) ? (string) $dataType : null;
        if (isset($input->dataType) && $dataType === null) {
            return [null, [['path' => $prefix . 'dataType', 'message' => 'identifiant (texte ou nombre) ou null attendu']]];
        }

        // chemins relatifs à la composition ; en page, préfixés par sections[i].composition
        $errors = array_map(
            fn ($e) => ['path' => $prefix === '' ? $e['path'] : rtrim($prefix . 'composition.' . $e['path'], '.'), 'message' => $e['message']],
            [...$this->checker->check($composition, $componentKey, $allowedMedia), ...$this->checker->missingBackgrounds($composition), ...$this->checker->sceneErrors($composition)]
        );
        $usesData = $this->data->familyUsesData($componentKey);
        $optional = $usesData && $this->data->dataOptional($componentKey);
        $ids = $availableIds[$componentKey] ?? [];
        $dataError = match (true) {
            !$usesData && $dataType !== null => 'cette famille n\'utilise pas de donnée : null attendu',
            $usesData && $ids && !in_array($dataType, $ids, true) && !($optional && $dataType === null) => sprintf('dataType à choisir parmi les données du site%s : %s', $optional ? ' (ou null pour toutes)' : '', implode(', ', array_slice($ids, 0, 20))),
            $usesData && !$ids && $dataType !== null => 'le site n\'a aucune donnée de ce type : null attendu (signale-le dans warnings)',
            default => null,
        };
        if ($dataError !== null) {
            $errors[] = ['path' => $prefix . 'dataType', 'message' => $dataError];
        }

        return $errors ? [null, $errors] : [['dataType' => $dataType, 'composition' => $composition], []];
    }

    /** @param list<string> $removed */
    private function logRepairs(array $removed): void
    {
        if ($removed) {
            $this->logger->info('Assistant IA : clés retirées sans nouvel essai (doublon, null, style sans effet, média de repli inventé)', ['keys' => array_slice($removed, 0, 10)]);
        }
    }

    /** @param list<string> $ids */
    private function logBackgrounds(array $ids): void
    {
        if ($ids) {
            $this->logger->info('Assistant IA : containers sans fond passés en transparent', ['count' => count($ids), 'ids' => array_slice($ids, 0, 10)]);
        }
    }

    /** @param list<array> $images */
    private function model(array $images): string
    {
        return $images ? $this->imagesModel() : $this->editModel;
    }

    /**
     * Boucle commune : appel, lecture de l'outil, évaluation par $handle (résultat ou erreurs), nouvel essai avec
     * les erreurs renvoyées au modèle (3 essais au total). $long : délais de la page et des requêtes avec images.
     *
     * @param callable(object, LandingAiUsageStats): array{0: mixed, 1: list<array{path: string, message: string}>} $handle
     */
    private function run(array $payload, string $tool, bool $long, callable $handle): mixed
    {
        $stats = new LandingAiUsageStats($payload['model']);
        $start = microtime(true);
        $errors = [];
        $timeouts = $long ? [self::PAGE_CALL_TIMEOUT, self::PAGE_TOTAL_TIMEOUT] : [self::CALL_TIMEOUT, self::TOTAL_TIMEOUT];

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $response = $this->call($payload, $start, $stats, ...$timeouts);

            $input = $this->toolInput($response, $tool);
            if ($input === null) {
                $errors = [['path' => '', 'message' => sprintf('réponse sans appel valide de l\'outil %s (arrêt : %s)', $tool, $response->stop_reason ?? '?')]];
            } else {
                [$result, $errors] = $handle($input, $stats);
                if ($result !== null) {
                    $stats->durationMs = $this->elapsedMs($start);

                    return $result;
                }
            }

            $this->logger->info('Assistant IA : proposition refusée', ['tool' => $tool, 'attempt' => $attempt, 'errors' => array_slice($errors, 0, 10)]);
            $payload['messages'][] = ['role' => 'assistant', 'content' => $response->content ?? []];
            $payload['messages'][] = $this->prompts->retryMessage($response, $errors, $tool);
        }

        $stats->durationMs = $this->elapsedMs($start);
        throw new LandingAiException(502, 'Proposition invalide', sprintf('L\'IA n\'a pas produit de composition valide après %d essais. Aucun crédit n\'a été consommé.', self::MAX_ATTEMPTS), array_slice($errors, 0, 40), [], $stats);
    }

    private function summary(object $input): string
    {
        return mb_substr(is_string($input->summary ?? null) ? $input->summary : '', 0, 1000);
    }

    private function call(array $payload, float $start, LandingAiUsageStats $stats, float $callTimeout, float $totalTimeout): object
    {
        $remaining = $totalTimeout - (microtime(true) - $start);
        if ($remaining < self::MIN_CALL_TIME) {
            $stats->durationMs = $this->elapsedMs($start);
            throw new LandingAiException(504, 'Délai dépassé', 'L\'IA n\'a pas répondu à temps. Aucun crédit n\'a été consommé.', [], [], $stats);
        }

        try {
            $response = $this->createMessageWithRetries($payload, $start, $callTimeout, $totalTimeout);
        } catch (LandingAiTimeoutException) {
            $stats->durationMs = $this->elapsedMs($start);
            throw new LandingAiException(504, 'Délai dépassé', 'L\'IA n\'a pas répondu à temps. Aucun crédit n\'a été consommé.', [], [], $stats);
        } catch (\Throwable $e) {
            $this->logger->error('Assistant IA : appel en échec', ['message' => $e->getMessage()]);
            $stats->durationMs = $this->elapsedMs($start);
            throw new LandingAiException(502, 'Service IA indisponible', 'Le service d\'IA est momentanément indisponible. Aucun crédit n\'a été consommé.', [], [], $stats);
        }

        $stats->attempts++;
        $stats->add($response->usage ?? null);

        if (($response->stop_reason ?? null) === 'refusal') {
            $stats->durationMs = $this->elapsedMs($start);
            throw new LandingAiException(502, 'Demande refusée', 'L\'IA a refusé cette demande. Reformulez-la. Aucun crédit n\'a été consommé.', [], [], $stats);
        }

        return $response;
    }

    /**
     * Appel à l'API, repris jusqu'à TRANSIENT_RETRIES fois sur une erreur passagère (délai : Retry-After de l'API,
     * sinon 2 puis 4 s), tant que le délai total le permet. Les autres erreurs remontent aussitôt.
     */
    private function createMessageWithRetries(array $payload, float $start, float $callTimeout, float $totalTimeout): object
    {
        for ($retry = 0; ; $retry++) {
            $remaining = $totalTimeout - (microtime(true) - $start);
            try {
                return $this->client->createMessage($payload, min($callTimeout, $remaining));
            } catch (AnthropicApiException $e) {
                $delay = min(self::MAX_RETRY_DELAY, $e->getRetryAfter() ?? 2 ** ($retry + 1));
                $remaining = $totalTimeout - (microtime(true) - $start); // l'appel en échec a pu durer
                if (!$e->isTransient() || $retry >= self::TRANSIENT_RETRIES || $remaining - $delay < self::MIN_CALL_TIME * 2) {
                    throw $e;
                }
                $this->logger->warning('Assistant IA : erreur passagère de l\'API, nouvel essai', ['status' => $e->getStatusCode(), 'retry' => $retry + 1, 'delay' => $delay]);
                if ($delay > 0) {
                    usleep($delay * 1000000);
                }
            }
        }
    }

    /** Entrée de l'outil, décodée en objets (les {} restent des objets), ou null */
    private function toolInput(object $response, string $tool): ?object
    {
        if (($response->stop_reason ?? null) === 'max_tokens') {
            return null;
        }
        foreach (is_array($response->content ?? null) ? $response->content : [] as $block) {
            if (is_object($block) && ($block->type ?? null) === 'tool_use' && ($block->name ?? null) === $tool && is_object($block->input ?? null)) {
                return CompositionEditApplier::copy($block->input);
            }
        }

        return null;
    }

    /** @return list<string> */
    private function warnings(mixed $warnings): array
    {
        $list = [];
        foreach (is_array($warnings) ? $warnings : [] as $warning) {
            if (is_string($warning) && trim($warning) !== '') {
                $list[] = mb_substr($warning, 0, 300);
            }
        }

        return array_slice($list, 0, 10);
    }

    private function elapsedMs(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
