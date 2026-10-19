<?php

namespace App\Services\LandingAiService;

use App\Dto\LandingAiComposeInputDto;
use App\Entity\AiJob;
use App\Entity\AiUsage;
use App\Message\LandingAiJobMessage;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Site complet (mode site, 15/10/2026) : une tâche de fond compose l'accueil (navbar, sections, footer, charte) ;
 * une tâche fille par page demandée est créée avec elle, crédits réservés, puis lancée quand l'accueil est composé,
 * avec sa charte et un résumé de son style. Le worker les traite l'une après l'autre. Pages d'un site :
 * - boutique : pages système (fiche produit, connexion, inscription, compte…), clés du catalogue ;
 * - landing page : onglets libres { title, brief? }, désignés par le slug de leur titre.
 * Le résultat de la tâche mère liste les tâches filles (pages[clé].jobId, et title pour un onglet) : le frontend lit
 * chacune sur GET /api/landingpage-ai/jobs/{jobId}. Échec de l'accueil : les filles échouent aussi, leurs crédits
 * sont libérés.
 */
final class LandingAiSiteOrchestrator
{
    public function __construct(
        private readonly LandingAiJobService $jobs,
        private readonly LandingAiQuotaService $quota,
        private readonly MessageBusInterface $bus,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionProvider $tenantProvider,
        private readonly LoggerInterface $logger
    ) {
    }

    /** Coût total d'un site : l'accueil, puis chaque page (page système ou onglet) */
    public static function cost(LandingAiComposeInputDto $dto): int
    {
        return LandingAiQuotaService::cost('site') + (count($dto->pages) + count($dto->tabs)) * LandingAiQuotaService::cost('page');
    }

    /**
     * Clés des pages d'un site, dans l'ordre demandé : clé de page système (boutique) ou slug du titre de l'onglet
     * (landing page : ascii minuscule, tirets, rendu unique par un suffixe -2, -3…).
     *
     * @return array<string, ?string> clé => titre de l'onglet (null pour une page système)
     */
    public static function pageKeys(LandingAiComposeInputDto $dto): array
    {
        if ($dto->app !== 'landingpage') {
            return array_fill_keys($dto->pages, null);
        }
        $keys = [];
        foreach ($dto->tabs as $tab) {
            $ascii = class_exists(\Transliterator::class) ? transliterator_transliterate('Any-Latin; Latin-ASCII', $tab['title']) : iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $tab['title']);
            $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $ascii)), '-') ?: 'onglet';
            $slug = $base;
            for ($n = 2; isset($keys[$slug]); $n++) {
                $slug = "$base-$n";
            }
            $keys[$slug] = $tab['title'];
        }

        return $keys;
    }

    /**
     * Entrées « pages » du résultat de l'accueil : tâche fille et état initial de chaque page, avec le titre d'un onglet.
     *
     * @return array<string, array{jobId: string, status: string, title?: string}>
     */
    public static function pageEntries(LandingAiComposeInputDto $dto): array
    {
        $titles = self::pageKeys($dto);
        $entries = [];
        foreach ($dto->children as $key => $jobId) {
            $entries[$key] = ['jobId' => $jobId, 'status' => 'pending'] + (isset($titles[$key]) ? ['title' => $titles[$key]] : []);
        }

        return $entries;
    }

    /**
     * Tâches filles (une par page système ou par onglet), en attente, crédits réservés ; pas encore envoyées au worker.
     *
     * @return array<string, string> clé de page (pageKeys) => identifiant de tâche
     */
    public function createChildren(LandingAiComposeInputDto $parent, ?string $user): array
    {
        $children = [];
        try {
            $tabs = array_values($parent->tabs);
            foreach (array_keys(self::pageKeys($parent)) as $i => $key) {
                $child = $parent->app === 'landingpage' ? $this->tabRequest($parent, $tabs[$i]) : $this->childRequest($parent, $key);
                $label = $parent->app === 'landingpage' ? $tabs[$i]['title'] : $key;
                $usage = $this->quota->reserve('page', '', LandingAiQuotaService::cost('page'), $user, sprintf('[site : %s] %s', $label, $parent->prompt), LandingAiQuotaService::SITE_PAGE_RESERVATION_TTL_SECONDS);
                $children[$key] = $this->jobs->create($usage, $user, $child->toJson())->getId();
            }
        } catch (\Throwable $e) {
            $parent->children = $children;
            $this->failChildren($parent, new LandingAiException(502, 'Service IA indisponible', 'Le site n\'a pas pu être mis en file. Aucun crédit n\'a été consommé.'));
            throw $e;
        }

        return $children;
    }

    /**
     * Accueil composé : chaque tâche fille reçoit la charte et le style de l'accueil, puis part au worker.
     *
     * @param array $body résultat de l'accueil (charter, siteStyle)
     */
    public function launchChildren(LandingAiComposeInputDto $parent, array $body): void
    {
        $em = $this->emProvider->getEntityManager();
        foreach ($parent->children as $page => $jobId) {
            $job = $em->find(AiJob::class, $jobId);
            if ($job === null || $job->getStatus() !== AiJob::STATUS_PENDING) {
                $this->logger->warning('Assistant IA : tâche fille introuvable ou déjà traitée', ['job' => $jobId, 'page' => $page]);
                continue;
            }
            $child = LandingAiComposeInputDto::fromStoredJob((string) $job->getInput());
            $child->charter = is_array($body['charter'] ?? null) && $body['charter'] !== [] ? (object) $body['charter'] : $child->charter;
            $child->siteStyle = is_string($body['siteStyle'] ?? null) ? $body['siteStyle'] : null;
            $job->requeue($child->toJson());
            $em->flush();
            $this->bus->dispatch(new LandingAiJobMessage($job->getId(), (string) $this->tenantProvider->getTenantCode()));
        }
    }

    /** Accueil en échec, ou questions posées sur l'accueil : les pages ne seront pas composées, crédits libérés */
    public function failChildren(LandingAiComposeInputDto $parent, LandingAiException $e, ?string $message = null): void
    {
        $em = $this->emProvider->getEntityManager();
        foreach ($parent->children as $jobId) {
            $job = $em->find(AiJob::class, $jobId);
            if ($job === null || $job->getStatus() !== AiJob::STATUS_PENDING) {
                continue;
            }
            $usage = $em->find(AiUsage::class, $job->getUsageId());
            if ($usage !== null && $usage->getStatus() === AiUsage::STATUS_RESERVED) {
                $this->quota->release($usage, null);
            }
            $this->jobs->fail($job, new LandingAiException($e->getStatusCode(), $e->getError(), $message ?? 'L\'accueil du site n\'a pas pu être composé : cette page n\'a pas été composée. Aucun crédit n\'a été consommé.'));
        }
    }

    /** L'assistant a posé des questions sur l'accueil : les pages attendent la nouvelle demande avec les réponses */
    public function cancelChildrenForQuestions(LandingAiComposeInputDto $parent): void
    {
        $this->failChildren($parent, new LandingAiException(409, 'Questions en attente', ''),
            'L\'assistant a posé des questions sur l\'accueil : cette page sera composée quand le site sera relancé avec les réponses. Aucun crédit n\'a été consommé.');
    }

    /** Requête d'un onglet d'une landing page : même demande, médias, images et réglages que l'accueil */
    private function tabRequest(LandingAiComposeInputDto $parent, array $tab): LandingAiComposeInputDto
    {
        $child = $this->childRequest($parent, null);
        $child->tab = $tab;

        return $child;
    }

    /** Requête d'une page système du site : même demande, médias, images et réglages que l'accueil */
    private function childRequest(LandingAiComposeInputDto $parent, ?string $page): LandingAiComposeInputDto
    {
        $child = new LandingAiComposeInputDto();
        $child->mode = 'page';
        $child->system = $page;
        $child->prompt = $parent->prompt;
        $child->locale = $parent->locale;
        $child->media = $parent->media;
        $child->images = $parent->images;
        $child->charter = $parent->charter;
        $child->style = $parent->style;
        $child->designMd = $parent->designMd;
        // les pages suivent l'accueil : elles reçoivent les réponses de l'administrateur mais ne posent pas de questions
        $child->answers = $parent->answers;
        $child->noQuestions = true;
        $child->history = $parent->history;

        return $child;
    }
}
