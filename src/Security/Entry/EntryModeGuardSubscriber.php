<?php

namespace App\Security\Entry;

use App\Import\ImportJobStore;
use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\Http\RequestPath;
use App\Services\Screens\ScreenRegistry;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * LA GARDE DU MODE AGENCE (lot AG-3b, 16/11/2026, `docs/ecrans-et-contextes.md` § 7). Elle s'applique à l'OPÉRATEUR EXTÉRIEUR
 * (`ExternalOperator`) : un membre d'agence entré dans le site d'un client par un jeton d'entrée. FERMÉE PAR DÉFAUT.
 *
 * 1. La route se classe par le registre des écrans (`ScreenRegistry::classify`, sur le chemin décodé). Elle n'est servie que si son
 *    écran est de la famille CONSTRUCTION et que son module est actif sur le site. Route non rangée, liste hors écran, autre famille,
 *    module éteint : 403 `MODE_FORBIDDEN`.
 * 2. Les LECTURES du site public (`@public`, GET) sont servies comme à un visiteur : l'authentification est retirée, le contrôleur ne
 *    voit aucun compte (sinon les rôles d'administrateur de l'opérateur y ouvriraient ce qu'un administrateur lit : un coût, une
 *    commande). Toute autre liste hors écran est fermée, y compris le compte (`@compte` : un jeton d'entrée n'en obtient pas d'autre).
 * 3. Restent FERMÉS, même s'ils sont de la construction (précisions du chef) : les clés d'API et d'activation, l'export, le paiement
 *    (Stripe), le changement des modules du site (le forfait de l'agence n'y est pas encore relu) ; l'écriture du coût d'achat et du
 *    coefficient d'un article ; l'import des clients et des commandes.
 * 4. La réponse d'un écran de construction perd ses CHAMPS SENSIBLES (`ScreenRegistry::SENSITIVE`, par suffixe du chemin du champ).
 *
 * Après le pare-feu (priorité 8), avec les autres gardes de portée (7). L'entrée du compte de la plateforme (mode super
 * administrateur) n'est pas bornée ici : il garde tous ses écrans de site.
 */
class EntryModeGuardSubscriber implements EventSubscriberInterface
{
    public const CODE = 'MODE_FORBIDDEN';
    public const OPEN = 'open';
    public const PUBLIC = 'public';
    public const CLOSED = 'closed';
    /** L'écran de la requête, posé pour le filtre de la réponse */
    public const ATTRIBUTE = '_entry_screen';
    /** Lot AG-ASSIST : l'assistance qui couvre la requête [numéro, section, méthode], posée pour la trace de la réponse */
    public const ASSISTANCE_ATTRIBUTE = '_entry_assistance';
    /** Toujours fermés pendant une assistance, en lecture comme en écriture : les comptes et leurs rôles (les clés, l'export, Stripe et les
     *  modules sont déjà dans CLOSED_PATHS) */
    private const ASSISTANCE_CLOSED = ['#(^|/)(users?|accounts?|comptes?|roles?|team-roles?)(/|$)#i'];
    /** Fermés en ÉCRITURE même en mode « modifier » : aucun paiement, remboursement ni encaissement */
    private const ASSISTANCE_NO_WRITE = ['#(refund|rembours|payment|paiement|encaiss|capture|charge|cash-out|payout)#i'];
    private const WRITE = ['POST', 'PUT', 'PATCH', 'DELETE'];
    /** Fermés même dans la construction : expression du chemin => [méthodes (vide : toutes), raison] */
    private const CLOSED_PATHS = [
        ['#^/api/app-keys(/|$)#', [], 'keys'],
        ['#(^|/)exports?(/|\.|$)#i', [], 'export'],
        ['#stripe#i', [], 'payment'],
        // les gestes de module (demander, essayer, allumer, éteindre) ; `PUT /api/site/features` se filtre par ses clés (bodyRefused)
        ['#^/api/site/features/[^/]+/(request|trial|enable|disable)$#', [], 'modules'],
    ];
    /** Champs du coût d'un article, jamais écrits par l'opérateur (le coût d'achat, et le coût de la commande fournisseur que lisent la
     *  marge, la valeur du stock et le résultat de période) */
    private const COST_FIELDS = ['purchasePrice', 'coefficientMultiplier', 'commande', 'commandeCostCents', 'commandeQuantity', 'commandeAttachedAt', 'commandeCostEstimated'];
    /** Champs de la fiche du site fermés à l'opérateur : l'adresse qui reçoit les commandes, les demandes et les réservations des
     *  clients ; la devise, le fuseau et la fréquence des périodes, qui changent le sens des montants enregistrés. Les TAXES (prix taxes
     *  comprises) restent ouvertes à l'agence : décision du propriétaire du 15/11/2026, le commerçant confirme « Taxes vérifiées » (AG-3d) */
    private const SITE_FIELDS = ['email', 'currency', 'posTimezone', 'purchasePeriodFrequency'];

    public function __construct(
        private readonly TokenStorageInterface $tokens,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly EntryContext $entry,
        private readonly ?ImportJobStore $imports = null,
        private readonly ?\App\Services\TenantConnectionProvider $tenants = null,
        private readonly ?\App\Services\Agency\Assistance\AssistanceService $assistance = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 7], KernelEvents::RESPONSE => ['onKernelResponse', -100]];
    }

    /**
     * La décision pour une route, sans rien lire d'autre que le registre (essayée sur toutes les routes du routeur).
     *
     * @return array{0: string, 1: ?string, 2: ?string} [open|public|closed, cible du registre, raison du refus]
     */
    public static function decision(string $method, string $path): array
    {
        $method = strtoupper($method);
        $target = ScreenRegistry::classify($method, $path);
        if (!str_starts_with($path, '/api/')) {
            return [self::CLOSED, $target, 'surface']; // l'API seulement : EasyAdmin est le lot AG-3c
        }
        if ($target === null) {
            return [self::CLOSED, null, 'unclassified'];
        }
        if (str_starts_with($target, '@')) {
            return $target === '@public' && in_array($method, ['GET', 'HEAD'], true) ? [self::PUBLIC, $target, null] : [self::CLOSED, $target, 'off_screen'];
        }
        if (ScreenRegistry::familyOf($target) !== ScreenRegistry::CONSTRUCTION) {
            return [self::CLOSED, $target, 'family'];
        }
        foreach (self::CLOSED_PATHS as [$pattern, $methods, $reason]) {
            if (preg_match($pattern, $path) === 1 && ($methods === [] || in_array($method, $methods, true))) {
                return [self::CLOSED, $target, $reason];
            }
        }

        return [self::OPEN, $target, null];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $user = $this->tokens->getToken()?->getUser();
        if (!$user instanceof ExternalOperator) {
            // l'entrée du compte de la plateforme garde tous ses écrans de site, mais n'écrit rien sur un compte (profil, adresse, mot
            // de passe : une telle écriture rendrait un jeton ordinaire)
            if ($this->entry->active() && !in_array($request->getMethod(), ['GET', 'HEAD'], true) && ScreenRegistry::classify($request->getMethod(), RequestPath::of($request)) === '@compte') {
                $event->setResponse($this->refusal('account'));
            }

            return;
        }
        $path = RequestPath::of($request);
        [$decision, $target, $reason] = self::decision($request->getMethod(), $path);
        if ($decision === self::PUBLIC) {
            $this->tokens->setToken(null); // servie EXACTEMENT comme à un visiteur

            return;
        }
        if ($decision === self::OPEN) {
            $reason = $this->moduleOff((string) $target) ?? $this->bodyRefused($request, (string) $target, $path) ?? $this->storefrontRefused($request, $path, $user);
        } elseif ($reason === 'family' && is_string($target)) {
            // lot AG-ASSIST : une section fermée à l'agence, ouverte par le COMMERÇANT pour une heure
            $reason = $this->assistanceRefused($request, $target, $path, $user);
            if ($reason === null) {
                return;
            }
        }
        if ($decision !== self::OPEN || $reason !== null) {
            $event->setResponse($this->refusal($reason));

            return;
        }
        $request->attributes->set(self::ATTRIBUTE, $target);
    }

    /**
     * Lot AG-ASSIST : la requête de l'opérateur est-elle couverte par une assistance vivante de SON agence sur CE site, pour la section
     * de l'écran ? null : servie (la requête porte alors le numéro de l'assistance, pour sa trace et l'auteur de ses écritures) ; sinon la
     * raison du refus. Lecture : toujours (hors comptes et listes de CLOSED_PATHS) ; écriture : seulement en mode « modifier », jamais un
     * paiement, un remboursement, un encaissement, ni la suppression d'un client.
     */
    private function assistanceRefused(Request $request, string $target, string $path, ExternalOperator $operator): ?string
    {
        if ($this->assistance === null || $this->moduleOff($target) !== null) {
            return 'family';
        }
        $sections = \App\Services\Agency\Assistance\AssistanceSections::of($target, ScreenRegistry::familyOf($target));
        $grant = $sections === [] ? null : $this->assistance->covering((string) ($this->tenants?->getTenantCode() ?? ''), $operator->getAgencyId(), $sections);
        if ($grant === null) {
            return 'family';
        }
        $method = $request->getMethod();
        foreach ([...array_map(static fn (array $c): string => $c[0], self::CLOSED_PATHS), ...self::ASSISTANCE_CLOSED] as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return 'assistance_closed';
            }
        }
        if (in_array($method, self::WRITE, true)) {
            if ($grant['mode'] !== \App\Services\Agency\Assistance\AssistanceSections::WRITE) {
                return 'assistance_read_only';
            }
            foreach (self::ASSISTANCE_NO_WRITE as $pattern) {
                if (preg_match($pattern, $path) === 1) {
                    return 'assistance_closed';
                }
            }
            if ($method === 'DELETE' && $grant['section'] === 'clients') {
                return 'assistance_closed';
            }
        }
        $operator->duringAssistance((int) $grant['id']);
        $request->attributes->set(self::ASSISTANCE_ATTRIBUTE, ['id' => (int) $grant['id'], 'section' => (string) $grant['section'], 'screen' => $target]);

        return null;
    }

    private function refusal(?string $reason): JsonResponse
    {
        return new JsonResponse(['error' => self::CODE, 'code' => self::CODE, 'reason' => $reason,
            'message' => 'Cette page n\'est pas ouverte à une agence dans le site de son client.'], 403);
    }

    /** Ouvrir ou fermer la boutique : seulement l'agence qui a CRÉÉ le site (un site rattaché en service ne se ferme pas par l'agence) */
    private function storefrontRefused(Request $request, string $path, ExternalOperator $operator): ?string
    {
        return preg_match('#^/api/site/storefront/(open|close)$#', $path) === 1 && $operator->getOrigin() !== 'created' ? 'storefront' : null;
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if ($event->isMainRequest() && is_array($request->attributes->get(self::ASSISTANCE_ATTRIBUTE))) {
            $this->traceAssistance($request, $event->getResponse()->getStatusCode());

            return; // une section ouverte par le commerçant : servie entière (ni champs sensibles de la construction à retirer, ni filtre)
        }
        $target = $request->attributes->get(self::ATTRIBUTE);
        $response = $event->getResponse();
        if (!is_string($target) || !$event->isMainRequest()) {
            return;
        }
        $this->traceWrite($request, $response->getStatusCode());
        if (!str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            return;
        }
        $specs = array_map(static fn (string $field): array => explode('.', str_replace('[]', '.[]', $field)), ScreenRegistry::SENSITIVE[$target]['fields'] ?? []);
        $isImportList = $target === 'imports' && RequestPath::of($event->getRequest()) === '/api/site/imports';
        if ($specs === [] && !$isImportList) {
            return;
        }
        $content = (string) $response->getContent();
        $data = json_decode($content);
        if (json_last_error() !== \JSON_ERROR_NONE) {
            // une réponse JSON qu'on ne sait pas relire ne part pas sans filtre
            $event->setResponse(new JsonResponse(['error' => 'ERROR', 'code' => 'ERROR', 'message' => 'Réponse indisponible.'], 500));

            return;
        }
        if ($isImportList && $data instanceof \stdClass && is_array($data->imports ?? null)) {
            // la liste mêle les natures : seuls les imports du catalogue restent
            $data->imports = array_values(array_filter($data->imports, static fn (mixed $job): bool => $job instanceof \stdClass && ($job->kind ?? null) === ImportJobStore::KIND_CATALOG));
        }
        $response->setContent((string) json_encode(self::strip($data, $specs), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * Retire d'une réponse décodée (objets en `stdClass`) les champs dont le chemin se TERMINE par l'un des chemins donnés ; `[]` y
     * désigne un élément de liste (`steps[].description` : la description de chaque étape).
     *
     * @param list<list<string>> $specs
     * @param list<string> $path
     */
    public static function strip(mixed $node, array $specs, array $path = []): mixed
    {
        if ($node instanceof \stdClass) {
            foreach (get_object_vars($node) as $key => $value) {
                $here = [...$path, (string) $key];
                if (self::matches($here, $specs)) {
                    unset($node->{$key});
                    continue;
                }
                $node->{$key} = self::strip($value, $specs, $here);
            }

            return $node;
        }
        if (is_array($node)) {
            foreach ($node as $index => $value) {
                $node[$index] = self::strip($value, $specs, [...$path, '[]']);
            }
        }

        return $node;
    }

    /** @param list<string> $path @param list<list<string>> $specs */
    private static function matches(array $path, array $specs): bool
    {
        foreach ($specs as $spec) {
            if (count($spec) <= count($path) && array_slice($path, -count($spec)) === $spec) {
                return true;
            }
        }

        return false;
    }

    /** Le module de l'écran est-il éteint sur ce site ? Un module éteint chez le client est fermé en entrée. */
    private function moduleOff(string $target): ?string
    {
        $module = ScreenRegistry::screen($target)['module'] ?? null;
        if ($module === null) {
            return null;
        }
        $column = FeatureCatalog::GOVERNED[$module] ?? null;
        if ($column === null) {
            return 'module';
        }
        $value = $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT ' . $column . ' FROM entreprise ORDER BY id LIMIT 1');

        return in_array($value, [true, 't', 'true', 1, '1'], true) ? null : 'module';
    }

    /**
     * Chaque écriture réussie de l'opérateur porte son nom au journal du site (« Agence X, prénom »), même quand la route n'écrit pas
     * elle-même au journal (écritures d'API Platform, médias, apparence, codes-barres…). Ne fait jamais échouer la réponse.
     */
    private function traceWrite(Request $request, int $status): void
    {
        $operator = $this->tokens->getToken()?->getUser();
        if (!in_array($request->getMethod(), self::WRITE, true) || $status >= 400 || !$operator instanceof ExternalOperator) {
            return;
        }
        try {
            $this->emProvider->getEntityManager()->getConnection()->insert('content_audit_log', [
                'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'), 'user_identifier' => mb_substr($operator->getUserIdentifier(), 0, 180),
                'tenant' => (string) ($this->tenants?->getTenantCode() ?? ''), 'resource' => \App\Services\Entry\EntryService::JOURNAL_RESOURCE, 'resource_id' => (string) $operator->getEntryId(),
                'action' => 'write', 'fields' => 'request', 'after_state' => json_encode(['method' => $request->getMethod(), 'path' => mb_substr(RequestPath::of($request), 0, 200), 'status' => $status], \JSON_UNESCAPED_SLASHES),
            ]);
        } catch (\Throwable) {
            // la trace manque : la réponse part quand même (l'écriture est faite)
        }
    }

    /**
     * Lot AG-ASSIST : CHAQUE requête faite pendant une assistance (écran consulté, fiche lue, écriture) est écrite au journal du site, lu
     * par « Accès extérieurs » : l'auteur « Agence X, prénom, pendant l'assistance n° N », la méthode, le chemin, la réponse.
     */
    private function traceAssistance(Request $request, int $status): void
    {
        $operator = $this->tokens->getToken()?->getUser();
        $grant = (array) $request->attributes->get(self::ASSISTANCE_ATTRIBUTE);
        if (!$operator instanceof ExternalOperator) {
            return;
        }
        $write = in_array($request->getMethod(), self::WRITE, true);
        try {
            $this->emProvider->getEntityManager()->getConnection()->insert('content_audit_log', [
                'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'), 'user_identifier' => mb_substr($operator->getUserIdentifier(), 0, 180),
                'tenant' => (string) ($this->tenants?->getTenantCode() ?? ''), 'resource' => \App\Services\Entry\EntryService::JOURNAL_RESOURCE, 'resource_id' => (string) $operator->getEntryId(),
                'action' => $write ? 'assistance_write' : 'assistance_read', 'fields' => 'request',
                'after_state' => json_encode(['assistance' => $grant['id'] ?? null, 'section' => $grant['section'] ?? null, 'screen' => $grant['screen'] ?? null,
                    'method' => $request->getMethod(), 'path' => mb_substr(RequestPath::of($request), 0, 200), 'query' => mb_substr((string) $request->getQueryString(), 0, 200), 'status' => $status], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable) {
            // la trace manque : la réponse part quand même
        }
    }

    /** Ce que le corps ou l'objet de la requête ferme : le coût d'un article, un champ réservé de la fiche ou du registre, un import de clients ou de commandes */
    private function bodyRefused(Request $request, string $target, string $path): ?string
    {
        if ($target === 'site.modules' && $path === '/api/site/features' && in_array($request->getMethod(), self::WRITE, true)) {
            $body = json_decode((string) $request->getContent(), true);
            foreach (is_array($body) && is_array($body['values'] ?? null) ? array_keys($body['values']) : [] as $key) {
                $reason = self::featureKeyRefused((string) $key);
                if ($reason !== null) {
                    return $reason;
                }
            }
        }
        if ($target === 'site.reglages' && preg_match('#^/api/entreprise(/|$)#', $path) === 1 && in_array($request->getMethod(), self::WRITE, true)) {
            $body = json_decode((string) $request->getContent(), true);
            if (is_array($body) && array_intersect(self::SITE_FIELDS, array_keys($body)) !== []) {
                return 'field';
            }
        }
        if ($target === 'catalogue' && in_array($request->getMethod(), self::WRITE, true)) {
            $body = json_decode((string) $request->getContent(), true);
            if ((is_array($body) && self::carriesCost($body)) || array_intersect(self::COST_FIELDS, array_keys($request->request->all())) !== []) {
                return 'cost';
            }
        }
        if ($target === 'imports') {
            if ($path === '/api/site/imports' && $request->getMethod() === 'POST') {
                return (string) $request->request->get('kind', ImportJobStore::KIND_CATALOG) === ImportJobStore::KIND_CATALOG ? null : 'kind';
            }
            if (preg_match('#^/api/site/imports/(\d+)(/|$)#', $path, $m) === 1) {
                $job = $this->imports?->find((int) $m[1]);

                return $job !== null && ($job['kind'] ?? null) !== ImportJobStore::KIND_CATALOG ? 'kind' : null;
            }
        }

        return null;
    }

    /**
     * Une clé du registre des fonctionnalités que l'agence n'écrit pas (LISTE BLANCHE, `FeatureCatalog::AGENCY_WRITABLE`) : `modules` pour
     * une clé `platform`, `field` pour toute autre, inconnue comprise ; null : clé de construction.
     */
    public static function featureKeyRefused(string $key): ?string
    {
        if (in_array($key, \App\Services\FeatureAccess\FeatureCatalog::AGENCY_WRITABLE, true)) {
            return null;
        }

        return \App\Services\FeatureAccess\FeatureCatalog::exists($key) && \App\Services\FeatureAccess\FeatureCatalog::get($key)['scope'] === \App\Services\FeatureAccess\FeatureCatalog::SCOPE_PLATFORM ? 'modules' : 'field';
    }

    /** @param array<mixed> $body */
    private static function carriesCost(array $body): bool
    {
        foreach ($body as $key => $value) {
            if (in_array($key, self::COST_FIELDS, true) || (is_array($value) && self::carriesCost($value))) {
                return true;
            }
        }

        return false;
    }
}
