<?php

namespace App\Services\SiteAssistantService;

use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\FeatureAccess\SiteFeatureStateService;
use App\Services\LandingAiService\LandingAiClientInterface;
use App\Services\LandingAiService\LandingAiException;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\LandingAiService\LandingAiUsageStats;
use App\Services\Platform\CustomDomainService;
use App\Services\Platform\PlatformModeService;
use App\Services\Platform\SiteReadinessService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Assistant IA de configuration du site (console de pilotage, 05/11/2026). Il connaît le registre des fonctionnalités, le
 * cheminement de préparation et les écrans, répond en langage courant et **propose** des actions par des outils bornés :
 * `set_site_settings` (clés `site` du registre) et `request_feature` (demande d'une fonctionnalité) ne sont jamais exécutés
 * directement : ils deviennent des actions en attente que l'administrateur confirme (ConfirmSiteAssistantActionUseCase, qui passe
 * par les mêmes cas d'usage que la page Mon site, donc validation, rôles et journal). `get_settings`, `get_readiness` et
 * `open_screen` sont immédiats et sans effet. Rien sur les paiements, la plateforme ou d'autres sites. Un échange coûte un crédit
 * IA du site (LandingAiQuotaService, mode `assistant`), remboursé si l'IA échoue. Modèle : Sonnet 5.5, 60 s.
 */
class SiteAssistantService
{
    public const MODEL = 'claude-sonnet-5-5';
    public const MODE = 'assistant';
    public const COMPONENT = 'site-config';
    public const COST = 1;
    public const MAX_ROUNDS = 4;
    public const MAX_MESSAGES = 20;
    public const MAX_MESSAGE_LENGTH = 4000;
    private const TIMEOUT = 60.0;
    private const ACTION_TTL = 900;

    public function __construct(
        private readonly LandingAiClientInterface $client,
        private readonly LandingAiQuotaService $quota,
        private readonly SiteFeatureStateService $state,
        private readonly SiteReadinessService $readiness,
        private readonly PlatformModeService $platform,
        private readonly CustomDomainService $domains,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<array{role: string, content: string}> $messages conversation (dernier message : l'utilisateur)
     * @return array{reply: string, actions: list<array<string, mixed>>, links: list<array{label: string, screen: string}>, handoffs: list<array{task: string, section: string, feature: ?string}>, usage: array<string, mixed>}
     * @throws LandingAiException 402 crédits, 429 trop d'échecs, 503 IA indisponible
     */
    public function chat(array $messages, ?string $user, string $tenantCode, string $language = 'fr', bool $fullAccess = false): array
    {
        $stats = new LandingAiUsageStats(self::MODEL);
        if ($fullAccess) {
            // Propriétaire de la plateforme (ROLE_SUPER_ADMIN, 07/11/2026) : accès complet sur tout site, aucun crédit du site décompté,
            // jamais bloqué par le quota ni par les échecs du site ; l'échange est seulement journalisé
            try {
                $result = $this->converse($messages, $stats, $tenantCode, $language, true);
                $this->logger->info(sprintf('[SiteAssistant] échange du propriétaire de la plateforme sur le site %s (%s)', $tenantCode, $user ?? '-'));

                return $result + ['usage' => $stats->toArray(), 'access' => 'full'];
            } catch (\Throwable $e) {
                $this->logger->error('[SiteAssistant] échange (accès complet) échoué : ' . $e->getMessage());
                if ($e instanceof LandingAiException) {
                    throw $e;
                }
                throw new LandingAiException(503, 'Assistant indisponible', 'L\'assistant ne répond pas pour le moment, réessayez dans un instant.');
            }
        }
        $last = $messages[array_key_last($messages)]['content'] ?? '';
        $usage = $this->quota->reserve(self::MODE, self::COMPONENT, self::COST, $user, mb_substr($last, 0, 200));
        try {
            $result = $this->converse($messages, $stats, $tenantCode, $language);
            $this->quota->complete($usage, $stats, mb_substr($result['reply'], 0, 200));

            return $result + ['usage' => $stats->toArray(), 'access' => 'site'];
        } catch (\Throwable $e) {
            $this->quota->release($usage, $stats);
            $this->logger->error('[SiteAssistant] échange échoué : ' . $e->getMessage());
            if ($e instanceof LandingAiException) {
                throw $e;
            }
            throw new LandingAiException(503, 'Assistant indisponible', 'L\'assistant ne répond pas pour le moment, réessayez dans un instant.');
        }
    }

    /** @return array<string, mixed>|null action en attente (outil, entrée, résumé), null si inconnue ou expirée */
    public function pendingAction(string $tenantCode, string $id): ?array
    {
        $key = self::actionKey($tenantCode, $id);
        $action = $this->cache->get($key, fn (ItemInterface $item) => $item->expiresAfter(1) ? null : null);
        if ($action === null) {
            $this->cache->delete($key);
        }

        return is_array($action) ? $action : null;
    }

    public function forgetAction(string $tenantCode, string $id): void
    {
        $this->cache->delete(self::actionKey($tenantCode, $id));
    }

    /** @return array{reply: string, actions: list<array<string, mixed>>, links: list<array{label: string, screen: string}>, handoffs: list<array{task: string, section: string, feature: ?string}>} */
    private function converse(array $messages, LandingAiUsageStats $stats, string $tenantCode, string $language, bool $fullAccess = false): array
    {
        $registry = $this->state->all();
        $readiness = $this->readiness->forCurrentSite();
        $payloadMessages = [];
        foreach ($messages as $m) {
            $payloadMessages[] = ['role' => $m['role'], 'content' => mb_substr((string) $m['content'], 0, self::MAX_MESSAGE_LENGTH)];
        }
        $actions = [];
        $links = [];
        $handoffs = [];
        $reply = '';
        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $stats->attempts++;
            $response = $this->client->createMessage([
                'model' => self::MODEL, 'max_tokens' => 1500, 'system' => $this->system($registry, $readiness, $language, $fullAccess), 'messages' => $payloadMessages, 'tools' => $this->tools(),
            ], self::TIMEOUT);
            $stats->add($response->usage ?? null);
            $content = is_array($response->content ?? null) ? $response->content : [];
            $texts = [];
            $toolUses = [];
            foreach ($content as $block) {
                if (($block->type ?? null) === 'text') {
                    $texts[] = (string) ($block->text ?? '');
                } elseif (($block->type ?? null) === 'tool_use') {
                    $toolUses[] = $block;
                }
            }
            $reply = trim(implode("\n", array_filter($texts))) ?: $reply;
            if ($toolUses === []) {
                break;
            }
            $payloadMessages[] = ['role' => 'assistant', 'content' => json_decode(json_encode($content), true)];
            $results = [];
            foreach ($toolUses as $use) {
                $input = json_decode(json_encode($use->input ?? new \stdClass()), true) ?: [];
                [$result, $action, $link] = $this->runTool((string) ($use->name ?? ''), $input, $registry, $readiness, $tenantCode);
                if ($action !== null) {
                    $actions[] = $action;
                }
                if ($link !== null) {
                    $links[] = $link;
                }
                if (($use->name ?? null) === 'delegate_to_editor' && ($result['handoff'] ?? null) !== null) {
                    $handoffs[] = $result['handoff'];
                }
                $results[] = ['type' => 'tool_result', 'tool_use_id' => (string) ($use->id ?? ''), 'content' => json_encode($result, JSON_UNESCAPED_UNICODE)];
            }
            $payloadMessages[] = ['role' => 'user', 'content' => $results];
        }
        if ($reply === '') {
            $reply = $actions !== [] ? 'Je vous propose les actions ci-dessous : confirmez-les pour que je les applique.' : 'Je n\'ai pas compris la demande ; pouvez-vous la reformuler ?';
        }

        return ['reply' => $reply, 'actions' => $actions, 'links' => $links, 'handoffs' => $handoffs];
    }

    /**
     * Exécute un outil immédiat ou enregistre une proposition.
     * @return array{0: mixed, 1: ?array<string, mixed>, 2: ?array{label: string, screen: string}} [résultat pour l'IA, action en attente, lien]
     */
    private function runTool(string $name, array $input, array $registry, array $readiness, string $tenantCode): array
    {
        switch ($name) {
            case 'get_settings':
                return [$this->compactRegistry($registry), null, null];
            case 'get_readiness':
                return [$readiness, null, null];
            case 'get_domain_status':
                return [$this->domains->status($tenantCode), null, null];
            case 'delegate_to_editor':
                $task = trim((string) ($input['task'] ?? ''));
                $section = (string) ($input['section'] ?? 'pages');
                if ($task === '' || !in_array($section, ['pages', 'commerce', 'site', 'landing', 'boutique'], true)) {
                    return [['error' => 'task et section (pages | commerce | site | landing | boutique) attendus'], null, null];
                }
                $handoff = ['task' => mb_substr($task, 0, 500), 'section' => $section, 'feature' => isset($input['feature']) && is_string($input['feature']) ? $input['feature'] : null];

                return [['delegated' => true, 'handoff' => $handoff, 'note' => 'Transmis à l\'assistant de l\'éditeur ; dis à l\'administrateur que l\'éditeur prend le relais.'], null, null];
            case 'set_custom_domain':
                $domain = trim((string) ($input['domain'] ?? ''));
                if ($domain === '') {
                    return [['error' => 'domaine attendu'], null, null];
                }
                $action = $this->remember($tenantCode, 'set_custom_domain', ['domain' => $domain], (string) ($input['summary'] ?? ('Enregistrer le domaine ' . $domain)));

                return [['proposed' => true, 'actionId' => $action['id'], 'note' => 'Proposé à l\'administrateur ; après confirmation, lui donner la procédure DNS (get_domain_status).'], $action, null];
            case 'open_screen':
                $screen = (string) ($input['screen'] ?? '');
                if (!isset(SiteAssistantTools::SCREENS[$screen])) {
                    return [['error' => 'écran inconnu', 'screens' => array_keys(SiteAssistantTools::SCREENS)], null, null];
                }

                return [['ok' => true, 'screen' => $screen, 'label' => SiteAssistantTools::SCREENS[$screen]], null, ['label' => SiteAssistantTools::SCREENS[$screen], 'screen' => $screen]];
            case 'set_site_settings':
                $values = is_array($input['values'] ?? null) ? $input['values'] : [];
                $errors = [];
                foreach ($values as $key => $value) {
                    if (!FeatureCatalog::exists((string) $key) || !FeatureCatalog::isWritable((string) $key, false)) {
                        $errors[] = ['path' => (string) $key, 'message' => 'clé inconnue ou réservée à la plateforme'];
                    }
                }
                if ($values === [] || $errors !== []) {
                    return [['error' => 'valeurs refusées', 'errors' => $errors ?: [['path' => 'values', 'message' => 'objet { clé: valeur } attendu']]], null, null];
                }
                $action = $this->remember($tenantCode, 'set_site_settings', ['values' => $values], (string) ($input['summary'] ?? 'Modifier les réglages du site'));

                return [['proposed' => true, 'actionId' => $action['id'], 'note' => 'Action proposée à l\'administrateur ; elle sera appliquée seulement s\'il la confirme.'], $action, null];
            case 'request_feature':
                $feature = (string) ($input['feature'] ?? '');
                if (!isset(FeatureCatalog::GOVERNED[$feature])) {
                    return [['error' => 'fonctionnalité inconnue', 'features' => array_keys(FeatureCatalog::GOVERNED)], null, null];
                }
                $action = $this->remember($tenantCode, 'request_feature', ['feature' => $feature, 'message' => mb_substr((string) ($input['message'] ?? ''), 0, 500)], 'Demander ' . FeatureCatalog::label($feature) . ' à la plateforme');

                return [['proposed' => true, 'actionId' => $action['id']], $action, null];
            default:
                return [['error' => 'outil inconnu'], null, null];
        }
    }

    /** @return array{id: string, tool: string, input: array<string, mixed>, summary: string} */
    private function remember(string $tenantCode, string $tool, array $input, string $summary): array
    {
        $id = bin2hex(random_bytes(8));
        $action = ['id' => $id, 'tool' => $tool, 'input' => $input, 'summary' => mb_substr($summary, 0, 200)];
        $this->cache->delete(self::actionKey($tenantCode, $id));
        $this->cache->get(self::actionKey($tenantCode, $id), function (ItemInterface $item) use ($action) {
            $item->expiresAfter(self::ACTION_TTL);

            return $action;
        });

        return $action;
    }

    private static function actionKey(string $tenantCode, string $id): string
    {
        return 'site_assistant.' . preg_replace('/[^a-z0-9_-]/i', '_', $tenantCode) . '.' . preg_replace('/[^a-f0-9]/', '', $id);
    }

    /** @return list<array<string, mixed>> le registre sans les champs d'affichage */
    private function compactRegistry(array $registry): array
    {
        return array_map(fn (array $e) => array_filter([
            'key' => $e['key'], 'label' => $e['label'], 'scope' => $e['scope'], 'value' => $e['value'], 'effective' => $e['effective'], 'status' => $e['status'],
            'options' => $e['options'] ?? null, 'prerequisites' => array_values(array_filter($e['prerequisites'], fn ($p) => !$p['ok'])) ?: null,
            'entitlement' => $e['entitlement'] ?? null, 'request' => $e['request'] ?? null,
        ], fn ($v) => $v !== null), $registry);
    }

    private function system(array $registry, array $readiness, string $language, bool $fullAccess = false): string
    {
        $shop = $this->platform->shopUrl();
        $lang = $language === 'en' ? 'Answer in English.' : 'Réponds en français.';
        $who = $fullAccess
            ? 'Tu parles au propriétaire de la plateforme (super administrateur), en accès complet : il agit sur ce site comme sur n\'importe quel autre, aucun crédit du site n\'est décompté, et il peut te demander toute action que tes outils permettent ; les écritures restent confirmées par lui avant d\'être appliquées. Les forfaits, droits et réglages de la plateforme se font dans sa page Plateforme du back-office : dis-le-lui quand il le faut.'
            : 'Tu parles à l\'administrateur de ce site.';

        return <<<TXT
Tu es l'assistant de configuration d'un site de la plateforme Arkanoa (site vitrine, boutique en ligne, caisse, Mémoires Vivantes, Boussole ESG, applications mobiles).
$who $lang Sois concret et court : une explication, puis l'action.
Ce que tu peux faire :
- expliquer un réglage, un prérequis ou une étape du cheminement « Préparer mon site » ;
- proposer de modifier les réglages du site (outil set_site_settings : seulement les clés de portée « site » du registre, avec la valeur exacte) ; l'administrateur confirme avant toute écriture ;
- proposer de demander une fonctionnalité non accordée (outil request_feature) ; {$this->shopHint($shop)}
- indiquer l'écran du back-office où agir (outil open_screen) quand l'étape se fait là-bas (produits, taxes, Stripe, transporteurs, clés d'activation, postes de caisse, formules d'abonnement, expéditeur des courriels) ;
- confier à l'assistant de l'éditeur du site (front) ce qui se fait là-bas (outil delegate_to_editor) : composer ou modifier les pages, choisir un modèle de site, régler depuis le panneau de l'éditeur ; tu ne composes jamais une page toi-même ;
- accompagner l'ajout d'un domaine propre : get_domain_status donne l'état, les enregistrements DNS attendus et la procédure (achat chez un registraire, enregistrement ici, DNS, propagation, certificat automatique) ; set_custom_domain propose l'enregistrement, l'administrateur confirme, puis il crée les enregistrements DNS et vérifie.
Ce que tu ne fais jamais : paiements, forfaits, droits de la plateforme, autres sites, données personnelles des clients, et aucune clé réservée à la plateforme (modules, applications). Si on te le demande, explique où le faire.
Avant de proposer, lis le contexte ci-dessous (registre et cheminement) ; n'invente aucune clé ni aucun écran. Propose une étape à la fois, dans l'ordre du cheminement.
Clés du registre modifiables : site.locale (fr|en|null), site.timezone (IANA|null), site.currency (CAD|EUR|USD), site.prices_include_tax (bool), commerce.sales_modes (liste parmi sale, rental, subscription), commerce.guest_checkout (bool), commerce.subscriptions (bool), commerce.tax_provider (table|stripe), shipping.mode (none|own_key|platform : none = estimation par transporteurs à prix fixe et retrait, le marchand expédie lui-même), shipping.easypost_environment (test|live), shipping.default_weight_kg (nombre), shipping.free_above (cents, null = aucune gratuité).
Écrans : {$this->screensHint()}.

REGISTRE DU SITE (JSON) :
{$this->json($this->compactRegistry($registry))}

CHEMINEMENT (JSON) :
{$this->json(['percent' => $readiness['percent'], 'next' => $readiness['next'], 'steps' => array_map(fn ($s) => ['key' => $s['key'], 'title' => $s['title'], 'status' => $s['status'], 'where' => $s['where'], 'action' => $s['action']], $readiness['steps'])])}
TXT;
    }

    private function shopHint(?string $shop): string
    {
        return $shop !== null ? "la boutique de forfaits de la plateforme est $shop : indique-la pour souscrire." : 'la plateforme répond par courriel.';
    }

    private function screensHint(): string
    {
        return implode(', ', array_map(fn ($k, $v) => "$k ($v)", array_keys(SiteAssistantTools::SCREENS), SiteAssistantTools::SCREENS));
    }

    /** @return list<array<string, mixed>> */
    private function tools(): array
    {
        return SiteAssistantTools::definitions();
    }

    private function json(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
