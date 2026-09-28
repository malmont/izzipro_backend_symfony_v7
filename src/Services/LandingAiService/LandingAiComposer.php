<?php

namespace App\Services\LandingAiService;

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
    private const MIN_CALL_TIME = 5.0;

    public function __construct(
        private readonly LandingAiClientInterface $client,
        private readonly LandingAiPromptBuilder $prompts,
        private readonly CompositionEditApplier $applier,
        private readonly LandingAiCompositionChecker $checker,
        private readonly LandingAiSiteContext $site,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default:landing_ai.default_model_edit:LANDING_AI_MODEL_EDIT)%')]
        private readonly string $editModel
    ) {
    }

    public function editModel(): string
    {
        return $this->editModel;
    }

    /**
     * Retouche : l'IA renvoie des opérations, appliquées par le serveur sur la composition actuelle.
     *
     * @param list<array> $media médias fournis par l'administrateur
     * @throws LandingAiException 502 (pas de composition valide, IA indisponible) ou 504 (délai)
     */
    public function edit(string $componentKey, object $composition, string $prompt, string $locale = 'fr', array $media = []): LandingAiEditResult
    {
        $stats = new LandingAiUsageStats($this->editModel);
        $start = microtime(true);

        $stored = $this->site->storedCompositions();
        $allowedMedia = $this->site->allowedMedia([...$stored, $composition], $media);
        $payload = $this->prompts->editPayload($this->editModel, $componentKey, $composition, $prompt, $locale, $media, $allowedMedia, $this->site->palette($stored));

        $errors = [];
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $response = $this->call($payload, $start, $stats);

            $input = $this->toolInput($response, LandingAiPromptBuilder::EDIT_TOOL);
            if ($input === null) {
                $errors = [['path' => '', 'message' => sprintf('réponse sans appel valide de l\'outil %s (arrêt : %s)', LandingAiPromptBuilder::EDIT_TOOL, $response->stop_reason ?? '?')]];
            } else {
                $operations = is_array($input->operations ?? null) ? $input->operations : [];
                $applied = $this->applier->apply($composition, $operations);
                $errors = $applied['errors'] ?: $this->checker->check($applied['composition'], $componentKey, $allowedMedia);
                if (!$errors) {
                    $stats->durationMs = $this->elapsedMs($start);

                    return new LandingAiEditResult(
                        $applied['composition'],
                        mb_substr(is_string($input->summary ?? null) ? $input->summary : '', 0, 1000),
                        $this->warnings($input->warnings ?? []),
                        $applied['touched'],
                        $operations,
                        $stats
                    );
                }
            }

            $this->logger->info('Assistant IA : proposition refusée', ['attempt' => $attempt, 'errors' => array_slice($errors, 0, 10)]);
            $payload['messages'][] = ['role' => 'assistant', 'content' => $response->content ?? []];
            $payload['messages'][] = $this->prompts->retryMessage($response, $errors);
        }

        $stats->durationMs = $this->elapsedMs($start);
        throw new LandingAiException(502, 'Proposition invalide', sprintf('L\'IA n\'a pas produit de composition valide après %d essais. Aucun crédit n\'a été consommé.', self::MAX_ATTEMPTS), array_slice($errors, 0, 40), [], $stats);
    }

    private function call(array $payload, float $start, LandingAiUsageStats $stats): object
    {
        $remaining = self::TOTAL_TIMEOUT - (microtime(true) - $start);
        if ($remaining < self::MIN_CALL_TIME) {
            $stats->durationMs = $this->elapsedMs($start);
            throw new LandingAiException(504, 'Délai dépassé', 'L\'IA n\'a pas répondu à temps. Aucun crédit n\'a été consommé.', [], [], $stats);
        }

        try {
            $response = $this->client->createMessage($payload, min(self::CALL_TIMEOUT, $remaining));
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
