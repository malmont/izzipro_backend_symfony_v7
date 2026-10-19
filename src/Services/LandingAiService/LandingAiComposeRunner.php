<?php

namespace App\Services\LandingAiService;

use App\Dto\LandingAiComposeInputDto;
use App\Entity\AiUsage;

/**
 * Exécute une demande déjà contrôlée et dont les crédits sont réservés : appel au moteur, puis consommation (succès)
 * ou libération (échec). Commun à la réponse synchrone (edit, create) et aux tâches de fond (page, images).
 */
final class LandingAiComposeRunner
{
    public function __construct(
        private readonly LandingAiComposer $composer,
        private readonly LandingAiQuotaService $quota,
        private readonly LandingAiCatalogue $catalogue,
        private readonly LandingAiCharterExtractor $charters
    ) {
    }

    /**
     * @return array corps de la réponse : { mode, composition | sections, dataType?, summary, warnings, contentChanges,
     *               groupChanges, limits, credits, usage } (propositions jamais appliquées : LandingAiContentProposals) ;
     *               ou, quand l'IA pose des questions : { mode, needsAnswers: true, questions, summary, warnings, credits, usage }
     * @throws LandingAiException crédits déjà libérés
     */
    public function run(LandingAiComposeInputDto $dto, AiUsage $usage): array
    {
        $options = $this->options($dto);
        try {
            $result = match ($dto->mode) {
                'create' => $this->composer->create((string) $dto->componentKey, $dto->prompt, $dto->locale, $dto->media, $dto->dataType !== null ? (string) $dto->dataType : null, $dto->images, $options),
                'page' => $this->composer->page($dto->prompt, $dto->locale, $dto->media, $dto->images, $dto->componentKey, $options),
                'site' => $this->composer->page($dto->prompt, $dto->locale, $dto->media, $dto->images, null, $options + ['siteHome' => true, 'siteApp' => $dto->app ?? 'boutique', 'siteTabs' => array_column($dto->tabs, 'title')]),
                default => $this->composer->edit((string) $dto->componentKey, $dto->composition, $dto->prompt, $dto->locale, $dto->media, $dto->images, $dto->dataType, $options),
            };
        } catch (LandingAiException $e) {
            $this->quota->release($usage, $e->getStats());
            throw $e;
        } catch (\Throwable $e) {
            $this->quota->release($usage, null);
            throw $e;
        }

        if ($result instanceof LandingAiQuestionsResult) {
            // questions à l'administrateur : rien n'est composé, aucun crédit facturé (l'appel reste compté dans les jetons)
            $this->quota->completeWithoutCharge($usage, $result->stats);

            return ['mode' => $dto->mode, 'needsAnswers' => true, 'questions' => $result->questions]
                + ($dto->system !== null ? ['system' => $dto->system] : []) + ($dto->tab !== null ? ['tab' => $dto->tab] : [])
                + ['summary' => $result->summary, 'warnings' => $result->warnings, 'contentChanges' => [], 'groupChanges' => [], 'limits' => [],
                    'credits' => $this->quota->credits(), 'usage' => $result->stats->toArray()];
        }
        $this->quota->complete($usage, $result->stats);

        $response = match ($dto->mode) {
            'page' => ['mode' => 'page', 'sections' => $result->sections] + ($dto->system !== null ? ['system' => $dto->system] : []) + ($dto->tab !== null ? ['tab' => $dto->tab] : []) + ($dto->charter !== null ? ['charter' => $dto->charter] : []),
            // site : l'accueil ; les pages (système ou onglets) sont des tâches filles (LandingAiSiteOrchestrator), lues à part
            'site' => ['mode' => 'site', 'app' => $dto->app ?? 'boutique', 'sections' => $result->sections, 'navbar' => $result->navbar, 'footer' => $result->footer, 'charter' => $result->charter,
                'siteStyle' => $this->charters->styleSummary($result->sections, $result->navbar, $result->footer),
                'pages' => LandingAiSiteOrchestrator::pageEntries($dto)],
            'create' => ['mode' => 'create', 'composition' => $result->composition, 'dataType' => $result->dataType],
            default => ['mode' => 'edit', 'composition' => $result->composition],
        };

        return $response + [
            'summary' => $result->summary,
            'warnings' => $result->warnings,
            'contentChanges' => $result->proposals['contentChanges'],
            'groupChanges' => $result->proposals['groupChanges'],
            'limits' => $result->proposals['limits'],
            'credits' => $this->quota->credits(),
            'usage' => $result->stats->toArray(),
        ];
    }

    /**
     * Options de la demande transmises au moteur : page système (description du catalogue, familles adaptées), onglet
     * d'une landing page, charte, réglages de style, DESIGN.md, style de l'accueil (page d'un site complet).
     *
     * @return array{system: ?array, tab: ?array, charter: ?object, style: ?array, designMd: ?string, siteStyle: ?string, answers: list<array>, noQuestions: bool, questionsAllowed: bool}
     */
    private function options(LandingAiComposeInputDto $dto): array
    {
        $system = null;
        if ($dto->system !== null && ($page = $this->catalogue->systemPages()[$dto->system] ?? null) !== null) {
            $system = ['key' => $dto->system, 'route' => $page['route'], 'title' => $page['title']['fr'] ?? $dto->system, 'required' => $page['required'], 'families' => $this->catalogue->familiesForRequired($page['required'])];
        }

        return ['system' => $system, 'tab' => $dto->tab, 'charter' => $dto->charter, 'style' => $dto->style, 'designMd' => $dto->designMd, 'siteStyle' => $dto->siteStyle,
            'answers' => $dto->answers, 'noQuestions' => $dto->noQuestions, 'questionsAllowed' => LandingAiComposer::questionsAllowed(['answers' => $dto->answers, 'noQuestions' => $dto->noQuestions], $dto->prompt)];
    }

    /** Encodage des réponses : la composition garde ses {} et ses nombres décimaux (1.0) */
    public static function encode(array $body): string
    {
        return json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
