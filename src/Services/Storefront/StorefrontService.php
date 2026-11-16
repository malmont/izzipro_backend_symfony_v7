<?php

namespace App\Services\Storefront;

use App\Cache\CacheTags;
use App\Services\Platform\TenantDatabaseConnector;
use App\Services\TenantCacheService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantPublicHostResolver;
use Psr\Log\LoggerInterface;

/**
 * La boutique « en construction » (lot CONSTRUCTION, 16/11/2026, `docs/boutique-en-construction.md`).
 *
 * Décisions du propriétaire : un site né SANS le module Boutique n'est jamais bloqué ; la construction ne ferme que la boutique en
 * ligne et, pour un site neuf, le site vitrine ; Mémoires Vivantes, Living Memory et Boussole n'en dépendent pas.
 *
 * L'état gardé sur la fiche du site (`entreprise.storefront_state`) :
 * - `construction` : la valeur de naissance de tout site neuf : la boutique ET le site vitrine sont fermés au public (étendue
 *   `site`) jusqu'au geste explicite « Ouvrir ». Éteindre ou suspendre le module Boutique n'ouvre PAS le site (décision du chef de
 *   projet, 16/11/2026) ; sans boutique, il s'ouvre d'un geste, sans moyen de paiement. Seul un site NÉ sans boutique n'est jamais
 *   bloqué : sa fabrication le pose à `open` (`born`).
 * - `boutique` : la boutique d'un site en service a été refermée : elle seule est fermée (étendue `shop`), tant que son module est
 *   actif.
 * - `open` : en service. C'est l'état de tout site d'avant ce lot. `storefront_opened_at` dit si la BOUTIQUE a été ouverte une
 *   fois : sans cette heure, une boutique active est en construction (étendue `shop`). C'est ainsi qu'une boutique activée plus
 *   tard sur un site en service naît en construction, sans aucun passage d'état à l'activation du module (rien à rater entre deux
 *   écritures). Une boutique déjà ouverte une fois n'est pas refermée par un module éteint puis rallumé.
 * `closure()` rend l'étendue effective : `site`, `shop`, ou null (rien de fermé).
 *
 * L'administrateur du site, ou le responsable de l'agence qui a créé le site, ouvre après une liste de contrôle qui avertit sans
 * bloquer, sauf sans moyen de paiement. Voir la vraie boutique avant : un compte d'équipe connecté, ou un LIEN D'APERÇU (jeton de
 * 64 caractères, `PREVIEW_DAYS` jours, un nouveau lien remplace l'ancien). Seule l'empreinte du jeton est gardée ; ni le jeton ni
 * l'en-tête qui le porte ne sont écrits dans un journal.
 *
 * Colonnes de la fiche lues et écrites en SQL direct. L'état du site COURANT se lit sur la connexion que la requête a déjà (ni base
 * maître, ni connexion de plus) et se garde en cache quelques secondes ; les gestes valent pour le site courant, ou pour un site géré
 * désigné par l'agence (`$site`), sans changer de site courant ; un geste vide le cache du site.
 */
class StorefrontService
{
    public const CONSTRUCTION = 'construction';
    public const SHOP_ONLY = 'boutique';
    public const OPEN = 'open';
    public const SCOPE_SITE = 'site';
    public const SCOPE_SHOP = 'shop';
    public const PREVIEW_DAYS = 7;
    public const PREVIEW_HEADER = 'X-Storefront-Preview';
    /** Durée de vie de l'état en cache, côté serveur et côté front (`Cache-Control`) */
    public const CACHE_SECONDS = 15;
    public const RESOURCE = 'storefront';
    private const CACHE_KEY = 'storefront_state';
    private const COLUMNS = 'name, locale, is_boutique_active, storefront_state, storefront_opened_at, storefront_preview_hash, storefront_preview_expires_at';

    /** Ce que le visiteur d'un site neuf en construction lit de la fiche du site : l'identité légale, les textes légaux, le logo, et les indicateurs des applications */
    public const LEGAL_FIELDS = ['id', 'name', 'logo', 'faviconUrl', 'email', 'tel', 'ein', 'tvaIntracommunautaire', 'conditionOfUse', 'LegalNotice', 'privacyPolicy',
        'adress', 'street1', 'street2', 'city', 'zip', 'country', 'province', 'currency', 'locale',
        // les applications lisent leur indicateur avant la connexion ; Mémoires Vivantes et Boussole ne dépendent pas de la boutique
        'isPosActive', 'isMobileShopActive', 'isManagementAppActive', 'isLivingMemoryActive', 'isMemoireVivanteActive', 'isBoussoleEsgActive'];

    /** @var array<string, array<string, mixed>> état lu pendant la requête, par code de site */
    private array $read = [];

    public function __construct(
        private readonly TenantConnectionManager $tenants,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantDatabaseConnector $connector,
        private readonly TenantCacheService $cache,
        private readonly TenantPublicHostResolver $hosts,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * L'état du site courant, gardé en cache quelques secondes. Lu sur la connexion du site que la requête a déjà. Une base pas encore
     * migrée, ou illisible, est tenue pour ouverte (jamais un site existant fermé par accident) : ce repli vaut pour la requête et
     * n'est gardé dans aucun cache. Un site sans fiche suit la valeur par défaut de sa base.
     *
     * @return array{state: string, shop: bool, name: string, locale: string, openedAt: ?string, previewHash: ?string, previewExpiresAt: ?string}
     */
    public function current(): array
    {
        $code = (string) $this->tenants->getCurrentTenantCode();
        if ($code === '') {
            return self::fallback('');
        }
        if (isset($this->read[$code])) {
            return $this->read[$code];
        }
        try {
            return $this->read[$code] = (array) $this->cache->get(self::CACHE_KEY, function () use ($code): array {
                $db = $this->emProvider->getEntityManager()->getConnection();

                return self::state($code, $db->fetchAssociative('SELECT ' . self::COLUMNS . ' FROM entreprise ORDER BY id LIMIT 1') ?: null,
                    fn (): string => (string) $db->fetchOne("SELECT COALESCE(column_default, '') FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'entreprise' AND column_name = 'storefront_state'"));
            }, self::CACHE_SECONDS, [...CacheTags::listTags(CacheTags::ENTREPRISE)]);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('État public du site %s illisible (%s) : tenu pour ouvert.', $code, $e::class));

            return $this->read[$code] = self::fallback($code);
        }
    }

    /** L'étendue de la construction du site courant : `site`, `shop`, ou null (rien n'est fermé) */
    public function closure(): ?string
    {
        return self::closureOf($this->current());
    }

    public function isUnderConstruction(): bool
    {
        return $this->closure() !== null;
    }

    /** Ce jeton d'aperçu est-il celui du site courant, encore valable ? Comparaison à temps constant, sur l'empreinte */
    public function previewValid(mixed $token): bool
    {
        $state = $this->current();
        if (!is_string($token) || preg_match('/^[0-9a-f]{64}$/', $token) !== 1 || $state['previewHash'] === null || $state['previewExpiresAt'] === null) {
            return false;
        }

        return hash_equals($state['previewHash'], hash('sha256', $token)) && new \DateTimeImmutable($state['previewExpiresAt'], new \DateTimeZone('UTC')) > new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Ce qu'un visiteur lit : l'état, son étendue, le nom, la langue, et si SA requête est en aperçu. Rien d'autre.
     *
     * @return array{state: string, scope: ?string, name: string, locale: string, openedAt: ?string, preview: array{active: bool, expiresAt: ?string}}
     */
    public function publicView(bool $preview, bool $byLink = false): array
    {
        $state = $this->current();
        $scope = self::closureOf($state);
        $active = $scope !== null && $preview;

        return ['state' => $scope !== null ? self::CONSTRUCTION : self::OPEN, 'scope' => $scope, 'name' => $state['name'], 'locale' => $state['locale'], 'openedAt' => $scope === null ? self::iso($state['openedAt']) : null,
            'preview' => ['active' => $active, 'expiresAt' => $active && $byLink ? self::iso($state['previewExpiresAt']) : null]];
    }

    /**
     * La liste de contrôle avant d'ouvrir. Elle avertit sans bloquer, sauf sans moyen de paiement (un compte de paiement connecté ET
     * actif). Sans le module Boutique (`shop` faux), la liste est vide : un site né sans boutique est « open », il n'y a rien à
     * ouvrir ; un site neuf dont la boutique a été éteinte reste fermé, et s'ouvre sans moyen de paiement.
     *
     * @param array{code: string, dbname: string}|null $site un site géré (agence) ; null : le site courant
     * @return array{state: string, scope: ?string, shop: bool, openedAt: ?string, canOpen: bool, items: list<array{key: string, ok: bool, blocking: bool, message: string}>}
     */
    public function checklist(?array $site = null): array
    {
        $site = $this->site($site);
        $pdo = $this->connector->pdo($site['dbname']);
        $state = $this->load($site, $pdo);
        $scope = self::closureOf($state);
        $one = static function (string $sql) use ($pdo): mixed {
            try {
                return $pdo->query($sql)->fetchColumn();
            } catch (\Throwable) {
                return null;
            }
        };
        $products = (int) $one('SELECT COUNT(*) FROM product p WHERE p.is_web = TRUE AND EXISTS (SELECT 1 FROM product_variant v WHERE v.product_id = p.id AND v.price > 0)') > 0;
        $payment = $this->tenants->isTenantInternal($site['code']) || (int) $one("SELECT COUNT(*) FROM stripe_config WHERE (COALESCE(account_id, '') <> '' AND is_active) OR (COALESCE(live_account_id, '') <> '' AND live_is_active)") > 0;
        $terms = (int) $one("SELECT COUNT(*) FROM entreprise_translation WHERE COALESCE(TRIM(condition_of_use), '') <> ''") > 0;
        // Sans le module Boutique (éteint sur un site jamais ouvert, pour n'en faire qu'un site d'applications), il n'y a rien à
        // contrôler : le site s'ouvre d'un geste, sans moyen de paiement
        $items = !$state['shop'] ? [] : [
            ['key' => 'products', 'ok' => $products, 'blocking' => false, 'message' => $products ? 'Des articles sont visibles sur la boutique.' : 'Aucun article n\'est visible sur la boutique.'],
            ['key' => 'payment', 'ok' => $payment, 'blocking' => !$payment, 'message' => $payment ? 'Un moyen de paiement est réglé.' : 'Aucun moyen de paiement n\'est réglé : la boutique ne peut pas encaisser.'],
            ['key' => 'terms', 'ok' => $terms, 'blocking' => false, 'message' => $terms ? 'Les conditions de vente sont remplies.' : 'Les conditions de vente sont vides.'],
        ];

        return ['state' => $scope !== null ? self::CONSTRUCTION : self::OPEN, 'scope' => $scope, 'shop' => $state['shop'], 'openedAt' => $scope === null ? self::iso($state['openedAt']) : null,
            'canOpen' => $scope !== null && array_filter($items, static fn (array $i): bool => $i['blocking']) === [], 'items' => $items];
    }

    /**
     * Ouvre au public : la boutique, ou le site entier s'il est neuf. Refus `STOREFRONT_NOT_READY` (409) sans moyen de paiement quand
     * la boutique est active. Rien à ouvrir (déjà ouvert, ou site né sans boutique) : rien ne change.
     *
     * @param array{code: string, dbname: string}|null $site
     * @return array{state: string, scope: ?string, openedAt: ?string}
     */
    public function open(?array $site, ?string $by): array
    {
        $site = $this->site($site);
        $checklist = $this->checklist($site);
        if ($checklist['scope'] === null) {
            return ['state' => self::OPEN, 'scope' => null, 'openedAt' => $checklist['openedAt']];
        }
        if (!$checklist['canOpen']) {
            throw new StorefrontException('STOREFRONT_NOT_READY', 409, ['items' => $checklist['items']]);
        }
        // l'heure d'ouverture est celle de la BOUTIQUE : un site ouvert sans elle la laisse vide, et sa boutique, activée plus tard,
        // naîtra en construction (`closureOf`). Elle suit la boutique LUE par la liste de contrôle (celle dont le paiement a été
        // vérifié), pas la colonne au moment de l'écriture : une boutique rendue entre les deux n'est pas ouverte sans contrôle
        $this->write($site, "UPDATE entreprise SET storefront_state = 'open', storefront_opened_at = ?, storefront_preview_hash = NULL, storefront_preview_expires_at = NULL WHERE id = (SELECT id FROM entreprise ORDER BY id LIMIT 1)",
            [$checklist['shop'] ? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s') : null], 'open', $by);
        $state = $this->load($site);

        return ['state' => self::OPEN, 'scope' => null, 'openedAt' => self::iso($state['openedAt'])];
    }

    /**
     * Referme la boutique d'un site en service : la boutique SEULE repasse en construction (ses commandes se ferment aussitôt), le
     * site vitrine reste servi. Le lien d'aperçu est retiré. Déjà en construction, ou sans module Boutique : rien ne change.
     *
     * @param array{code: string, dbname: string}|null $site
     * @return array{state: string, scope: ?string, openedAt: ?string}
     */
    public function close(?array $site, ?string $by): array
    {
        $site = $this->site($site);
        $state = $this->load($site);
        // seule une boutique ouverte se referme : sans module Boutique, ou déjà en construction, rien n'est écrit
        if (self::closureOf($state) === null && $state['shop']) {
            $this->write($site, "UPDATE entreprise SET storefront_state = 'boutique', storefront_preview_hash = NULL, storefront_preview_expires_at = NULL WHERE id = (SELECT id FROM entreprise ORDER BY id LIMIT 1)", [], 'close', $by);
            $state = $this->load($site);
        }
        $scope = self::closureOf($state);

        return ['state' => $scope !== null ? self::CONSTRUCTION : self::OPEN, 'scope' => $scope, 'openedAt' => null];
    }

    /**
     * La fin de la fabrication d'un site neuf, une fois sa fiche écrite et ses modules posés (agence, formulaire de la plateforme).
     * Avec la boutique : le site naît fermé en entier. NÉ SANS elle : il est en service, jamais bloqué ; si la boutique lui vient
     * plus tard, elle seule sera en construction. Rend faux si l'état n'a pas pu être posé (le site reste alors fermé en entier, sa
     * valeur de naissance : son administrateur l'ouvre d'un geste).
     */
    public function born(string $tenantCode, bool $shopAsked = false): bool
    {
        // la boutique était demandée mais son droit n'a pas pu être posé : le site reste fermé (son administrateur l'ouvre d'un geste)
        return $this->transition($tenantCode, 'UPDATE entreprise SET storefront_state = CASE WHEN is_boutique_active OR ' . ($shopAsked ? 'TRUE' : 'FALSE') . " THEN 'construction' ELSE 'open' END, storefront_opened_at = NULL,"
            . ' storefront_preview_hash = NULL, storefront_preview_expires_at = NULL WHERE id = (SELECT id FROM entreprise ORDER BY id LIMIT 1)', 'naissance du site');
    }

    /** Un passage de l'état d'un site désigné par son code, en une instruction ; jamais bloquant pour l'appelant */
    private function transition(string $tenantCode, string $sql, string $what): bool
    {
        $site = $this->connector->tenant($tenantCode);
        if ($site === null) {
            return false;
        }
        try {
            $stmt = $this->connector->pdo($site['dbname'])->prepare($sql);
            $stmt->execute();
            $changed = $stmt->rowCount() === 1;
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Boutique en construction, %s sur %s : état non posé (%s).', $what, $tenantCode, $e::class));

            return false;
        }
        unset($this->read[$site['code']]);
        $this->connector->invalidateSiteCache($site['code'], $site['dbname']);

        return $changed;
    }

    /**
     * Un lien d'aperçu neuf, qui remplace l'ancien : `https://<adresse du site>/apercu#token=<jeton>` (jeton dans le fragment, jamais
     * dans les paramètres). Le jeton n'est rendu qu'ici, une fois ; la base n'en garde que l'empreinte. Refus sur une boutique
     * ouverte : il n'y a rien à montrer en aperçu.
     *
     * @param array{code: string, dbname: string}|null $site
     * @return array{url: string, expiresAt: string}
     */
    public function previewLink(?array $site, ?string $by): array
    {
        $site = $this->site($site);
        if (self::closureOf($this->load($site)) === null) {
            throw new StorefrontException('STOREFRONT_ALREADY_OPEN', 409);
        }
        $host = $this->hosts->describe($site['code'])['host'] ?? null;
        if (!is_string($host) || $host === '') {
            throw new StorefrontException('STOREFRONT_NO_SHEET', 409);
        }
        $token = bin2hex(random_bytes(32));
        $expires = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+' . self::PREVIEW_DAYS . ' days');
        $this->write($site, 'UPDATE entreprise SET storefront_preview_hash = ?, storefront_preview_expires_at = ? WHERE id = (SELECT id FROM entreprise ORDER BY id LIMIT 1)',
            [hash('sha256', $token), $expires->format('Y-m-d H:i:s')], 'preview_link', $by);

        return ['url' => 'https://' . $host . '/apercu#token=' . $token, 'expiresAt' => $expires->format(\DateTimeInterface::ATOM)];
    }

    /**
     * La fiche du site réduite à `LEGAL_FIELDS` : même forme (les fronts lisent les mêmes champs), tout le reste remis à sa valeur vide
     * (présentation, réseaux sociaux, référencement, pixel de suivi, modules de la boutique et du site vitrine).
     */
    public static function legalOnly(\App\Dto\EntrepriseDto $dto): \App\Dto\EntrepriseDto
    {
        $out = clone $dto;
        foreach (array_keys(get_object_vars($out)) as $field) {
            if (in_array($field, self::LEGAL_FIELDS, true)) {
                continue;
            }
            $out->{$field} = match (true) {
                is_bool($out->{$field}) => false,
                is_array($out->{$field}) => [],
                default => null,
            };
        }

        return $out;
    }

    /**
     * L'étendue effective, CALCULÉE depuis la fiche (aucun passage d'état à l'activation d'un module : rien à rater).
     * - Un site neuf jamais ouvert (`construction`) reste fermé en entier jusqu'au geste « Ouvrir », que sa boutique soit active,
     *   éteinte ou suspendue : éteindre un module n'ouvre jamais un site.
     * - Sur un site en service, une boutique ACTIVE est en construction tant qu'elle n'a jamais été ouverte (pas d'heure
     *   d'ouverture), ou quand elle a été refermée (`boutique`). Sans module Boutique, rien n'est fermé.
     *
     * @param array<string, mixed> $state
     */
    private static function closureOf(array $state): ?string
    {
        return match (true) {
            $state['state'] === self::CONSTRUCTION => self::SCOPE_SITE,
            !$state['shop'] => null,
            $state['state'] === self::SHOP_ONLY, $state['openedAt'] === null => self::SCOPE_SHOP,
            default => null,
        };
    }

    /** @param array{code: string, dbname: string}|null $site @return array{code: string, dbname: string} */
    private function site(?array $site): array
    {
        $site ??= $this->connector->tenant((string) $this->tenants->getCurrentTenantCode());
        if ($site === null) {
            throw new StorefrontException('STOREFRONT_NO_SHEET', 409);
        }

        return ['code' => (string) $site['code'], 'dbname' => (string) $site['dbname']];
    }

    /**
     * L'état d'un site désigné, lu dans sa base (gestes de l'administrateur et de l'agence : jamais en cache).
     *
     * @param array{code: string, dbname: string} $site
     * @return array{state: string, shop: bool, name: string, locale: string, openedAt: ?string, previewHash: ?string, previewExpiresAt: ?string}
     */
    private function load(array $site, ?\PDO $pdo = null): array
    {
        $pdo ??= $this->connector->pdo($site['dbname']);
        $row = $pdo->query('SELECT ' . self::COLUMNS . ' FROM entreprise ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC);

        return self::state($site['code'], $row ?: null,
            static fn (): string => (string) $pdo->query("SELECT COALESCE(column_default, '') FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'entreprise' AND column_name = 'storefront_state'")->fetchColumn());
    }

    /**
     * @param array<string, mixed>|null $row la fiche du site, ou null s'il n'en a pas encore
     * @param callable(): string $default la valeur par défaut de la colonne dans la base de ce site
     * @return array{state: string, shop: bool, name: string, locale: string, openedAt: ?string, previewHash: ?string, previewExpiresAt: ?string}
     */
    private static function state(string $code, ?array $row, callable $default): array
    {
        if ($row === null) {
            // Pas encore de fiche : un site neuf est déjà en construction (la valeur par défaut de la colonne, posée à sa création, le dit),
            // et sa fiche naîtra avec le module Boutique actif ; un site d'avant ce lot sans fiche reste ouvert
            return str_contains($default(), self::CONSTRUCTION) ? ['state' => self::CONSTRUCTION, 'shop' => true] + self::fallback($code) : self::fallback($code);
        }

        return ['state' => in_array($row['storefront_state'], [self::CONSTRUCTION, self::SHOP_ONLY], true) ? (string) $row['storefront_state'] : self::OPEN,
            'shop' => in_array($row['is_boutique_active'], [true, 't', 'true', 1, '1'], true),
            'name' => trim((string) $row['name']) !== '' ? (string) $row['name'] : $code, 'locale' => in_array($row['locale'], ['fr', 'en'], true) ? (string) $row['locale'] : 'fr',
            'openedAt' => $row['storefront_opened_at'], 'previewHash' => $row['storefront_preview_hash'], 'previewExpiresAt' => $row['storefront_preview_expires_at']];
    }

    /**
     * Une écriture sur la fiche du site : refus sans fiche, cache du site vidé, ligne au journal du site (qui, quel geste ; jamais le jeton).
     *
     * @param array{code: string, dbname: string} $site
     * @param list<mixed> $params
     */
    private function write(array $site, string $sql, array $params, string $action, ?string $by): void
    {
        $pdo = $this->connector->pdo($site['dbname']);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->rowCount() !== 1) {
            throw new StorefrontException('STOREFRONT_NO_SHEET', 409);
        }
        unset($this->read[$site['code']]);
        $this->connector->invalidateSiteCache($site['code'], $site['dbname']);
        try {
            $pdo->prepare("INSERT INTO content_audit_log (created_at, user_identifier, tenant, resource, action, fields) VALUES (NOW(), ?, ?, ?, ?, 'state')")
                ->execute([$by !== null ? mb_substr($by, 0, 180) : null, $site['code'], self::RESOURCE, $action]);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('État public du site %s : geste « %s » non écrit au journal (%s).', $site['code'], $action, $e::class));
        }
    }

    /** @return array{state: string, shop: bool, name: string, locale: string, openedAt: ?string, previewHash: ?string, previewExpiresAt: ?string} */
    private static function fallback(string $code): array
    {
        return ['state' => self::OPEN, 'shop' => false, 'name' => $code, 'locale' => 'fr', 'openedAt' => null, 'previewHash' => null, 'previewExpiresAt' => null];
    }

    private static function iso(?string $at): ?string
    {
        return $at === null ? null : (new \DateTimeImmutable($at, new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
    }
}
