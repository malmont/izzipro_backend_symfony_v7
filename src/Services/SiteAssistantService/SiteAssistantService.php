<?php

namespace App\Services\SiteAssistantService;

use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\OrderService\OrderOperationsService as OrderOperationsMessages;
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
        private readonly ?\App\Services\ShippingService\ProductDimensionsService $dimensions = null, // poids des produits (lot 1 bis, 07/11/2026)
        private readonly ?\App\Services\ShippingService\ShippingDimensionsEstimator $estimator = null,
        // console IA, lot 6 (07/11/2026) : paliers (droits ai.help / ai.full), commandes et opérations, cheminements de configuration
        private readonly ?\App\Services\Platform\EntitlementService $entitlements = null,
        private readonly ?\App\Services\OrderService\OrderDeskService $orders = null,
        private readonly ?\App\Services\OrderService\OrderOperationsService $operations = null,
        private readonly ?\App\Services\OrderService\OrderShipmentService $shipments = null,
        private readonly ?\App\Services\Platform\SiteConfigurationPathsService $configuration = null,
        private readonly ?\App\Services\TenantEntityManagerProvider $emProvider = null,
        private readonly ?\App\Services\Help\HelpCatalogService $help = null, // aide intégrée (07/11/2026)
        private readonly ?\App\Services\Platform\SiteControlsService $controls = null, // contrôles (07/11/2026)
    ) {
    }

    /**
     * Palier de l'assistant pour le site (lot 6) : `full` pour le propriétaire de la plateforme et en mode démo de la plateforme ;
     * sinon selon les droits d'usage ai.full puis ai.help ; `none` = assistant non inclus (403).
     */
    public function tier(string $tenantCode, bool $fullAccess): string
    {
        if ($fullAccess || $this->entitlements === null || $this->platform->isDemo()) {
            return 'full';
        }
        try {
            if ($this->entitlements->isEnabled($tenantCode, 'ai.full')) {
                return 'full';
            }

            return $this->entitlements->isEnabled($tenantCode, 'ai.help') ? 'help' : 'none';
        } catch (\Throwable $e) {
            $this->logger->error('[SiteAssistant] palier illisible : ' . $e->getMessage());

            return 'none';
        }
    }

    /**
     * @param list<array{role: string, content: string}> $messages conversation (dernier message : l'utilisateur)
     * @return array{reply: string, actions: list<array<string, mixed>>, links: list<array{label: string, screen: string}>, handoffs: list<array{task: string, section: string, feature: ?string}>, usage: array<string, mixed>}
     * @throws LandingAiException 402 crédits, 429 trop d'échecs, 503 IA indisponible
     */
    public function chat(array $messages, ?string $user, string $tenantCode, string $language = 'fr', bool $fullAccess = false): array
    {
        $stats = new LandingAiUsageStats(self::MODEL);
        $tier = $this->tier($tenantCode, $fullAccess);
        if ($tier === 'none') {
            throw new LandingAiException(403, 'Assistant non inclus', 'L\'assistant IA n\'est pas inclus dans votre forfait : obtenez « Aide IA » ou « IA complète » depuis « Mon site : pilotage ».');
        }
        if ($fullAccess) {
            // Propriétaire de la plateforme (ROLE_SUPER_ADMIN, 07/11/2026) : accès complet sur tout site, aucun crédit du site décompté,
            // jamais bloqué par le quota ni par les échecs du site ; l'échange est seulement journalisé
            try {
                $result = $this->converse($messages, $stats, $tenantCode, $language, true, 'full');
                $this->logger->info(sprintf('[SiteAssistant] échange du propriétaire de la plateforme sur le site %s (%s)', $tenantCode, $user ?? '-'));

                return $result + ['usage' => $stats->toArray(), 'access' => 'full', 'tier' => 'full'];
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
            $result = $this->converse($messages, $stats, $tenantCode, $language, false, $tier);
            $this->quota->complete($usage, $stats, mb_substr($result['reply'], 0, 200));

            return $result + ['usage' => $stats->toArray(), 'access' => 'site', 'tier' => $tier];
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
    private function converse(array $messages, LandingAiUsageStats $stats, string $tenantCode, string $language, bool $fullAccess = false, string $tier = 'full'): array
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
                'model' => self::MODEL, 'max_tokens' => 1500, 'system' => $this->system($registry, $readiness, $language, $fullAccess, $tier), 'messages' => $payloadMessages, 'tools' => $this->tools($tier),
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
                [$result, $action, $link] = $this->runTool((string) ($use->name ?? ''), $input, $registry, $readiness, $tenantCode, $fullAccess, $tier);
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
    private function runTool(string $name, array $input, array $registry, array $readiness, string $tenantCode, bool $fullAccess = false, string $tier = 'full'): array
    {
        if ($tier === 'help' && !in_array($name, SiteAssistantTools::HELP_TOOLS, true)) {
            return [['error' => 'outil réservé au forfait « IA complète » : explique à l\'administrateur comment faire lui-même, ou où obtenir le forfait (Mon site : pilotage)'], null, null];
        }
        if (in_array($name, SiteAssistantTools::ORDER_TOOLS, true) || in_array($name, ['get_orders_todo', 'get_order'], true)) {
            return $this->runOrderTool($name, $input, $tenantCode);
        }
        switch ($name) {
            case 'get_help':
                if ($this->help === null) {
                    return [['error' => 'aide indisponible'], null, null];
                }
                $key = trim((string) ($input['key'] ?? ''));
                if ($key !== '') {
                    $page = $this->help->get($key);

                    return $page === null ? [['error' => 'page inconnue', 'pages' => array_column($this->help->all(), 'key')], null, null] : [['key' => $page['key'], 'title' => $page['title'], 'screen' => $page['screen'], 'text' => strip_tags(str_replace(['</p>', '</li>', '</h2>', '</h3>'], "\n", $page['html']))], null, ['label' => 'Aide : ' . $page['title'], 'screen' => 'aide']];
                }
                $query = trim((string) ($input['query'] ?? ''));

                return [['pages' => array_map(fn ($p) => ['key' => $p['key'], 'title' => $p['title'], 'summary' => $p['summary'], 'screen' => $p['screen']], $query === '' ? $this->help->all() : $this->help->search($query))], null, null];
            case 'get_controls':
                if ($this->controls === null) {
                    return [['error' => 'contrôles indisponibles'], null, null];
                }
                $overview = $this->controls->overview();

                return [['problems' => $overview['problems'], 'warnings' => $overview['warnings'], 'controls' => array_values(array_filter(array_map(fn ($c) => ['key' => $c['key'], 'title' => $c['title'], 'count' => $c['count'], 'severity' => $c['severity'], 'description' => $c['description'], 'screen' => $c['screen']], $overview['controls']), fn ($c) => $c['count'] > 0))], null, ['label' => SiteAssistantTools::SCREENS['controles'], 'screen' => 'controles']];
            case 'set_entreprise_fields':
                $fields = is_array($input['fields'] ?? null) ? $input['fields'] : [];
                $clean = [];
                $errors = [];
                foreach ($fields as $key => $value) {
                    if (!in_array((string) $key, SiteAssistantTools::ENTREPRISE_FIELDS, true)) {
                        $errors[] = ['path' => (string) $key, 'message' => 'champ inconnu ou réservé'];
                    } elseif (!is_string($value) && !is_null($value)) {
                        $errors[] = ['path' => (string) $key, 'message' => 'texte attendu'];
                    } else {
                        $clean[(string) $key] = $value === null ? null : mb_substr($value, 0, in_array($key, ['LegalNotice', 'conditionOfUse', 'privacyPolicy', 'apropos'], true) ? 20000 : 500);
                    }
                }
                if ($clean === [] || $errors !== []) {
                    return [['error' => 'champs refusés', 'errors' => $errors ?: [['path' => 'fields', 'message' => 'objet { champ: valeur } attendu']], 'allowed' => SiteAssistantTools::ENTREPRISE_FIELDS], null, null];
                }
                $action = $this->remember($tenantCode, 'set_entreprise_fields', ['fields' => $clean], (string) ($input['summary'] ?? ('Mettre à jour la fiche Entreprise : ' . implode(', ', array_keys($clean)))));

                return [['proposed' => true, 'actionId' => $action['id'], 'fields' => array_keys($clean), 'note' => 'Proposé à l\'administrateur ; appliqué seulement s\'il confirme. Pour un texte long, résume-lui ce qu\'il contient.'], $action, ['label' => SiteAssistantTools::SCREENS['entreprise'], 'screen' => 'entreprise']];
            case 'get_configuration_paths':
                if ($this->configuration === null) {
                    return [['error' => 'cheminements indisponibles'], null, null];
                }
                $paths = [];
                foreach ($this->configuration->overview() as $key => $path) {
                    $paths[$key] = ['title' => $path['title'], 'level' => $path['level'], 'levelLabel' => $path['levelLabel'], 'steps' => array_map(fn ($st) => ['key' => $st['key'], 'title' => $st['title'], 'status' => $st['status'], 'description' => $st['description'], 'screen' => $st['screen']], $path['steps'])];
                }

                return [$paths, null, ['label' => SiteAssistantTools::SCREENS['configuration'], 'screen' => 'configuration']];
            case 'estimate_product_dimensions':
                if ($this->dimensions === null || $this->estimator === null) {
                    return [['error' => 'estimation indisponible'], null, null];
                }
                $limit = max(1, min((int) ($input['limit'] ?? \App\Services\ShippingService\ShippingDimensionsEstimator::MAX_PRODUCTS), \App\Services\ShippingService\ShippingDimensionsEstimator::MAX_PRODUCTS));
                $rows = $this->dimensions->rows(true, null, $limit);
                if ($rows === []) {
                    return [['proposals' => [], 'note' => 'Tous les produits à expédier ont déjà une fiche poids.'], null, null];
                }
                try {
                    $estimated = $this->estimator->estimate($rows, $this->dimensions->classes(), null, 'fr', !$fullAccess);
                } catch (LandingAiException $e) {
                    return [['error' => $e->getMessage()], null, null];
                }
                $names = array_column($rows, 'name', 'productId');
                $proposals = array_map(fn ($p) => $p + ['name' => $names[$p['productId']] ?? ''], $estimated['proposals']);
                if ($proposals === []) {
                    return [['proposals' => [], 'note' => 'Aucune proposition exploitable.'], null, null];
                }
                $action = $this->remember($tenantCode, 'apply_product_dimensions', ['rows' => array_map(fn ($p) => ['productId' => $p['productId'], 'weightKg' => $p['weightKg'], 'lengthCm' => $p['lengthCm'], 'widthCm' => $p['widthCm'], 'heightCm' => $p['heightCm'], 'shippingClassId' => $p['shippingClassId']], $proposals)], sprintf('Enregistrer le poids et les dimensions estimés de %d produit(s)', count($proposals)));

                return [['proposed' => true, 'actionId' => $action['id'], 'proposals' => $proposals, 'note' => 'Présente les propositions (poids, dimensions, confiance) et demande confirmation ; l\'administrateur peut aussi les corriger dans la grille « Poids et dimensions ».'], $action, ['label' => SiteAssistantTools::SCREENS['product_weights'], 'screen' => 'product_weights']];
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

    /**
     * Commandes et opérations de support (lot 6) : lectures immédiates (à traiter, fiche), propositions confirmées pour les opérations
     * (remboursement, annulation, capture, renvoi de courriel, expédition), appliquées par ConfirmSiteAssistantActionUseCase.
     * @return array{0: mixed, 1: ?array<string, mixed>, 2: ?array{label: string, screen: string}}
     */
    private function runOrderTool(string $name, array $input, string $tenantCode): array
    {
        if ($this->orders === null || $this->operations === null || $this->shipments === null || $this->emProvider === null) {
            return [['error' => 'commandes indisponibles'], null, null];
        }
        $link = ['label' => SiteAssistantTools::SCREENS['orders'], 'screen' => 'orders'];
        if ($name === 'get_orders_todo') {
            $todo = $this->orders->todo(10);
            $compact = fn (array $rows) => array_map(fn ($r) => ['orderId' => $r['id'], 'reference' => $r['reference'], 'date' => $r['date'], 'status' => $r['status'], 'totalCents' => $r['totalCents'], 'customer' => $r['customer']], $rows);

            return [['counts' => $todo['counts'], 'toShip' => $compact($todo['toShip']), 'toCapture' => $compact($todo['toCapture']), 'incomplete' => $compact($todo['incomplete']), 'inTransit' => $compact($todo['inTransit'])], null, $link];
        }
        $order = $this->findOrder($input);
        if ($order === null) {
            return [['error' => 'commande introuvable : donne orderId ou reference'], null, null];
        }
        $sheet = $this->orders->sheet($order, '', 'fr');
        if ($name === 'get_order') {
            $o = $sheet['order'];

            return [['orderId' => $o['id'], 'reference' => $o['reference'], 'status' => $sheet['statusLabel'], 'statusId' => $sheet['statusId'], 'paymentStatus' => $o['paymentStatus'], 'orderDate' => $o['orderDate'], 'totalCents' => $o['totalAmount'], 'currency' => $sheet['currency'],
                'money' => ['capturedCents' => $sheet['money']['capturedCents'], 'refundedCents' => $sheet['money']['refundedCents'], 'refundableCents' => $sheet['money']['refundableCents'], 'authorizedCents' => $sheet['money']['authorizedCents']],
                'customer' => $sheet['customer'] ? ['name' => $sheet['customer']['name'], 'email' => $sheet['customer']['email'], 'ordersCount' => $sheet['customer']['ordersCount']] : null,
                'items' => array_map(fn ($it) => ['name' => $it['productVariantName'], 'quantity' => $it['quantity'], 'totalCents' => $it['totalPrice']], $o['orderItems']),
                'shipment' => $sheet['shipment'] + ['hasAddress' => $o['shippingAdress'] !== null, 'carrier' => $o['carrier']['name'] ?? null],
                'timeline' => array_slice($sheet['timeline'], -8), 'actions' => $sheet['actions'],
                'note' => 'Les actions à true sont possibles maintenant (outils refund_order, cancel_order, capture_order, ship_order, resend_order_email) ; counterSale = vente au comptoir, aucune opération ici.'], null, $link];
        }
        $a = $sheet['actions'];
        $id = (int) $order->getId();
        $ref = (string) $order->getReference();
        switch ($name) {
            case 'refund_order':
                if (!$a['refund']) {
                    return [['error' => $a['counterSale'] ? OrderOperationsMessages::MESSAGES['COUNTER_SALE'] : 'rien à rembourser sur cette commande', 'refundableCents' => $sheet['money']['refundableCents']], null, null];
                }
                $amount = isset($input['amountCents']) ? (int) $input['amountCents'] : (int) $sheet['money']['refundableCents'];
                if ($amount <= 0 || $amount > (int) $sheet['money']['refundableCents']) {
                    return [['error' => 'montant invalide ou au-delà du remboursable', 'refundableCents' => $sheet['money']['refundableCents']], null, null];
                }
                $reason = mb_substr(trim((string) ($input['reason'] ?? '')), 0, 200);
                $action = $this->remember($tenantCode, 'refund_order', ['orderId' => $id, 'amountCents' => $amount, 'reason' => $reason, 'notify' => ($input['notify'] ?? true) !== false], sprintf('Rembourser %s %s sur la commande %s%s', number_format($amount / 100, 2, ',', ' '), $sheet['currency'], $ref, $reason !== '' ? ' (' . $reason . ')' : ''));
                break;
            case 'cancel_order':
                if (!$a['cancel']) {
                    return [['error' => $a['counterSale'] ? OrderOperationsMessages::MESSAGES['COUNTER_SALE'] : 'commande expédiée, livrée ou déjà annulée : remboursez-la plutôt'], null, null];
                }
                $reason = mb_substr(trim((string) ($input['reason'] ?? '')), 0, 200);
                if ($reason === '') {
                    return [['error' => 'motif obligatoire'], null, null];
                }
                $action = $this->remember($tenantCode, 'cancel_order', ['orderId' => $id, 'reason' => $reason, 'notify' => ($input['notify'] ?? true) !== false], sprintf('Annuler la commande %s (%s)%s', $ref, $reason, $sheet['money']['capturedCents'] > 0 ? ' et rembourser le paiement' : ''));
                break;
            case 'capture_order':
                if (!$a['capture']) {
                    return [['error' => OrderOperationsMessages::MESSAGES['NOTHING_TO_CAPTURE']], null, null];
                }
                $action = $this->remember($tenantCode, 'capture_order', ['orderId' => $id], sprintf('Capturer le paiement autorisé de %s %s sur la commande %s', number_format($sheet['money']['authorizedCents'] / 100, 2, ',', ' '), $sheet['currency'], $ref));
                break;
            case 'resend_order_email':
                $kind = (string) ($input['kind'] ?? '');
                if (!$a['emails'] || !in_array($kind, ['confirmation', 'receipt', 'shipped'], true)) {
                    return [['error' => !$a['emails'] ? OrderOperationsMessages::MESSAGES['NO_EMAIL'] : 'kind : confirmation | receipt | shipped'], null, null];
                }
                if ($kind === 'shipped' && $sheet['shipment']['shippedAt'] === null) {
                    return [['error' => OrderOperationsMessages::MESSAGES['NOT_SHIPPED']], null, null];
                }
                $action = $this->remember($tenantCode, 'resend_order_email', ['orderId' => $id, 'kind' => $kind], sprintf('Renvoyer %s de la commande %s au client', ['confirmation' => 'la confirmation', 'receipt' => 'le reçu', 'shipped' => 'l\'avis d\'expédition'][$kind], $ref));
                break;
            case 'ship_order':
                if (!$a['ship']) {
                    return [['error' => $this->shipments->shipBlocker($order) ?? 'expédition impossible'], null, null];
                }
                $carrier = mb_substr(trim((string) ($input['carrier'] ?? '')), 0, 100);
                if ($carrier === '') {
                    return [['error' => 'transporteur obligatoire'], null, null];
                }
                $tracking = mb_substr(trim((string) ($input['trackingNumber'] ?? '')), 0, 100);
                $action = $this->remember($tenantCode, 'ship_order', ['orderId' => $id, 'carrier' => $carrier, 'trackingNumber' => $tracking !== '' ? $tracking : null, 'trackingUrl' => mb_substr(trim((string) ($input['trackingUrl'] ?? '')), 0, 500) ?: null, 'notify' => ($input['notify'] ?? true) !== false], sprintf('Marquer la commande %s expédiée par %s%s', $ref, $carrier, $tracking !== '' ? ' (suivi ' . $tracking . ')' : ''));
                break;
            default:
                return [['error' => 'outil inconnu'], null, null];
        }

        return [['proposed' => true, 'actionId' => $action['id'], 'summary' => $action['summary'], 'note' => 'Proposé à l\'administrateur ; appliqué seulement s\'il confirme.'], $action, $link];
    }

    private function findOrder(array $input): ?\App\Entity\Order
    {
        $repo = $this->emProvider->getEntityManager()->getRepository(\App\Entity\Order::class);
        if (isset($input['orderId']) && (int) $input['orderId'] > 0) {
            return $repo->find((int) $input['orderId']);
        }
        $reference = trim((string) ($input['reference'] ?? ''));

        return $reference !== '' ? $repo->findOneBy(['reference' => $reference]) : null;
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

    private function system(array $registry, array $readiness, string $language, bool $fullAccess = false, string $tier = 'full'): string
    {
        $shop = $this->platform->shopUrl();
        $lang = $language === 'en' ? 'Answer in English.' : 'Réponds en français.';
        $who = $fullAccess
            ? 'Tu parles au propriétaire de la plateforme (super administrateur), en accès complet : il agit sur ce site comme sur n\'importe quel autre, aucun crédit du site n\'est décompté, et il peut te demander toute action que tes outils permettent ; les écritures restent confirmées par lui avant d\'être appliquées. Les forfaits, droits et réglages de la plateforme se font dans sa page Plateforme du back-office : dis-le-lui quand il le faut.'
            : ($tier === 'help'
                ? 'Tu parles à l\'administrateur de ce site, au forfait « Aide IA » : tu expliques, tu lis (réglages, cheminements, commandes) et tu l\'orientes vers le bon écran, mais tu ne proposes aucune modification ni opération ; si on te le demande, explique comment le faire soi-même et où obtenir le forfait « IA complète » (Mon site : pilotage).'
                : 'Tu parles à l\'administrateur de ce site.');
        $ops = $tier === 'help' ? '' : <<<OPS

- lire les commandes à traiter (outil get_orders_todo) et une commande (get_order), puis proposer une opération de support : rembourser (refund_order, total ou partiel, motif), annuler (cancel_order, motif obligatoire, le paiement est remboursé), capturer un paiement autorisé (capture_order), marquer expédiée avec le suivi (ship_order), renvoyer un courriel (resend_order_email) ; l'administrateur confirme chaque opération ; jamais sur une vente au comptoir (la caisse s'en charge) ;
- lire les cheminements de configuration (get_configuration_paths : paiements Stripe, taxes, courriels, avis clients) pour guider pas à pas ;
- lire les contrôles du site (get_controls : ce qui cloche dans les données) et proposer de corriger ;
OPS;

        return <<<TXT
Tu es l'assistant de configuration d'un site de la plateforme Arkanoa (site vitrine, boutique en ligne, caisse, Mémoires Vivantes, Boussole ESG, applications mobiles).
$who $lang Sois concret et court : une explication, puis l'action.
Ce que tu peux faire :
- expliquer un réglage, un prérequis ou une étape du cheminement « Préparer mon site » ;
- proposer de modifier les réglages du site (outil set_site_settings : seulement les clés de portée « site » du registre, avec la valeur exacte) ; l'administrateur confirme avant toute écriture ;
- remplir la fiche Entreprise (outil set_entreprise_fields : nom, courriel, téléphone, site web, textes légaux, à-propos, référencement, numéros de taxe) ; pour les textes légaux (mentions légales, CGV ou conditions d'utilisation, politique de confidentialité), rédige toi-même un texte complet et adapté au pays et à l'activité de l'entreprise, en HTML simple, puis propose-le ; l'administrateur confirme ;
- proposer de demander une fonctionnalité non accordée (outil request_feature) ; {$this->shopHint($shop)}
- estimer par l'IA le poids et les dimensions des produits sans fiche (outil estimate_product_dimensions, un crédit par appel) et proposer de les enregistrer ; l'administrateur confirme ;
- lire l'aide intégrée (outil get_help : une page par sujet, écrite pour l'administrateur) et t'en servir pour expliquer, en citant la page ;
- indiquer l'écran du back-office où agir (outil open_screen) quand l'étape se fait là-bas (produits, taxes, Stripe, transporteurs, clés d'activation, postes de caisse, formules d'abonnement, expéditeur des courriels) ;
- confier à l'assistant de l'éditeur du site (front) ce qui se fait là-bas (outil delegate_to_editor) : composer ou modifier les pages, choisir un modèle de site, régler depuis le panneau de l'éditeur ; tu ne composes jamais une page toi-même ;
- accompagner l'ajout d'un domaine propre : get_domain_status donne l'état, les enregistrements DNS attendus et la procédure (achat chez un registraire, enregistrement ici, DNS, propagation, certificat automatique) ; set_custom_domain propose l'enregistrement, l'administrateur confirme, puis il crée les enregistrements DNS et vérifie.$ops
Ce que tu ne fais jamais : forfaits et droits de la plateforme, autres sites, modification des données personnelles des clients, et aucune clé réservée à la plateforme (modules, applications). Si on te le demande, explique où le faire.
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
    private function tools(string $tier = 'full'): array
    {
        return SiteAssistantTools::definitionsFor($tier);
    }

    private function json(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
