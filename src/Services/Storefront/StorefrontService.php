<?php

namespace App\Services\Storefront;

use App\Services\Platform\TenantDatabaseConnector;
use App\Services\TenantCacheService;
use App\Services\TenantConnectionManager;
use App\Services\TenantPublicHostResolver;
use Psr\Log\LoggerInterface;

/**
 * L'état public d'un site (lot CONSTRUCTION, 16/11/2026, `docs/boutique-en-construction.md`) : « en construction » ou « ouvert ».
 *
 * Tout site neuf naît en construction : le visiteur n'obtient que « Bientôt ouvert » au nom de la boutique ; les commandes et les
 * formulaires publics sont fermés à tous (`StorefrontGateSubscriber`). L'administrateur du site, ou le responsable de l'agence qui a
 * créé le site, l'ouvre après une liste de contrôle qui avertit sans bloquer, sauf sans aucun moyen de paiement quand la boutique en
 * ligne fait partie du site. Voir la vraie boutique avant l'ouverture : un compte d'équipe connecté, ou un LIEN D'APERÇU (un jeton
 * de 64 caractères, valable `PREVIEW_DAYS` jours ; un nouveau lien remplace l'ancien). Seule l'empreinte du jeton est gardée ; ni le
 * jeton ni l'en-tête qui le porte ne sont écrits dans un journal.
 *
 * Quatre colonnes de la fiche du site, en SQL direct : les gestes valent pour le site courant, ou pour un site géré désigné par
 * l'agence (`$site`), sans changer de site courant. L'état est gardé en cache quelques secondes ; un geste vide le cache du site.
 */
class StorefrontService
{
    public const CONSTRUCTION = 'construction';
    public const OPEN = 'open';
    public const PREVIEW_DAYS = 7;
    public const PREVIEW_HEADER = 'X-Storefront-Preview';
    /** Durée de vie de l'état en cache, côté serveur et côté front (`Cache-Control`) */
    public const CACHE_SECONDS = 15;
    public const RESOURCE = 'storefront';
    private const CACHE_KEY = 'storefront_state';

    /** @var array<string, array<string, mixed>> état lu pendant la requête, par code de site */
    private array $read = [];

    public function __construct(
        private readonly TenantConnectionManager $tenants,
        private readonly TenantDatabaseConnector $connector,
        private readonly TenantCacheService $cache,
        private readonly TenantPublicHostResolver $hosts,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * L'état du site courant, en cache quelques secondes. Un site sans fiche, ou une base pas encore migrée, est « ouvert » : rien à
     * protéger, et jamais un site existant fermé par accident.
     *
     * @return array{state: string, name: string, locale: string, openedAt: ?string, previewHash: ?string, previewExpiresAt: ?string}
     */
    public function current(): array
    {
        $code = (string) $this->tenants->getCurrentTenantCode();
        if ($code === '') {
            return self::fallback('');
        }

        return $this->read[$code] ??= (array) $this->cache->get(self::CACHE_KEY, function () use ($code): array {
            $site = $this->connector->tenant($code);

            return $site === null ? self::fallback($code) : $this->load($site);
        }, self::CACHE_SECONDS);
    }

    public function isUnderConstruction(): bool
    {
        return $this->current()['state'] === self::CONSTRUCTION;
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
     * Ce qu'un visiteur lit : l'état, le nom, la langue, et si SA requête est en aperçu. Rien d'autre.
     *
     * @return array{state: string, name: string, locale: string, openedAt: ?string, preview: array{active: bool, expiresAt: ?string}}
     */
    public function publicView(bool $preview, bool $byLink = false): array
    {
        $state = $this->current();
        $active = $state['state'] === self::CONSTRUCTION && $preview;

        return ['state' => $state['state'], 'name' => $state['name'], 'locale' => $state['locale'], 'openedAt' => self::iso($state['openedAt']),
            'preview' => ['active' => $active, 'expiresAt' => $active && $byLink ? self::iso($state['previewExpiresAt']) : null]];
    }

    /**
     * La liste de contrôle avant d'ouvrir. Elle avertit sans bloquer, sauf sans aucun moyen de paiement quand la boutique en ligne
     * fait partie du site.
     *
     * @param array{code: string, dbname: string}|null $site un site géré (agence) ; null : le site courant
     * @return array{state: string, openedAt: ?string, canOpen: bool, items: list<array{key: string, ok: bool, blocking: bool, message: string}>}
     */
    public function checklist(?array $site = null): array
    {
        $site = $this->site($site);
        $pdo = $this->connector->pdo($site['dbname']);
        $state = $this->load($site, $pdo);
        $one = static function (string $sql) use ($pdo): mixed {
            try {
                return $pdo->query($sql)->fetchColumn();
            } catch (\Throwable) {
                return null;
            }
        };
        $shop = in_array($one('SELECT is_boutique_active FROM entreprise ORDER BY id LIMIT 1'), [true, 't', 'true', 1, '1'], true);
        $products = (int) $one('SELECT COUNT(*) FROM product p WHERE p.is_web = TRUE AND EXISTS (SELECT 1 FROM product_variant v WHERE v.product_id = p.id AND v.price > 0)') > 0;
        $payment = $this->tenants->isTenantInternal($site['code']) || (int) $one("SELECT COUNT(*) FROM stripe_config WHERE COALESCE(account_id, '') <> '' OR COALESCE(live_account_id, '') <> ''") > 0;
        $terms = (int) $one("SELECT COUNT(*) FROM entreprise_translation WHERE COALESCE(TRIM(condition_of_use), '') <> ''") > 0;
        $items = [
            ['key' => 'products', 'ok' => $products, 'blocking' => false, 'message' => $products ? 'Des articles sont visibles sur la boutique.' : 'Aucun article n\'est visible sur la boutique.'],
            ['key' => 'payment', 'ok' => $payment, 'blocking' => $shop && !$payment, 'message' => $payment ? 'Un moyen de paiement est réglé.' : 'Aucun moyen de paiement n\'est réglé : la boutique ne peut pas encaisser.'],
            ['key' => 'terms', 'ok' => $terms, 'blocking' => false, 'message' => $terms ? 'Les conditions de vente sont remplies.' : 'Les conditions de vente sont vides.'],
        ];

        return ['state' => $state['state'], 'openedAt' => self::iso($state['openedAt']), 'canOpen' => array_filter($items, static fn (array $i): bool => $i['blocking']) === [], 'items' => $items];
    }

    /**
     * Ouvre le site au public. Refus `STOREFRONT_NOT_READY` (409) si un point de la liste bloque. Déjà ouvert : rien ne change.
     *
     * @param array{code: string, dbname: string}|null $site
     * @return array{state: string, openedAt: ?string}
     */
    public function open(?array $site, ?string $by): array
    {
        $site = $this->site($site);
        $checklist = $this->checklist($site);
        if ($checklist['state'] === self::OPEN) {
            return ['state' => self::OPEN, 'openedAt' => $checklist['openedAt']];
        }
        if (!$checklist['canOpen']) {
            throw new StorefrontException('STOREFRONT_NOT_READY', 409, ['items' => $checklist['items']]);
        }
        $this->write($site, "UPDATE entreprise SET storefront_state = 'open', storefront_opened_at = NOW(), storefront_preview_hash = NULL, storefront_preview_expires_at = NULL WHERE id = (SELECT id FROM entreprise ORDER BY id LIMIT 1)", [], 'open', $by);
        $state = $this->load($site);

        return ['state' => $state['state'], 'openedAt' => self::iso($state['openedAt'])];
    }

    /**
     * Referme le site (retour en construction) : les commandes et les formulaires se ferment aussitôt.
     *
     * @param array{code: string, dbname: string}|null $site
     * @return array{state: string, openedAt: ?string}
     */
    public function close(?array $site, ?string $by): array
    {
        $site = $this->site($site);
        if ($this->load($site)['state'] !== self::CONSTRUCTION) {
            $this->write($site, "UPDATE entreprise SET storefront_state = 'construction', storefront_opened_at = NULL WHERE id = (SELECT id FROM entreprise ORDER BY id LIMIT 1)", [], 'close', $by);
        }

        return ['state' => self::CONSTRUCTION, 'openedAt' => null];
    }

    /**
     * Un lien d'aperçu neuf, qui remplace l'ancien : `https://<adresse du site>/apercu#token=<jeton>` (jeton dans le fragment, jamais
     * dans les paramètres). Le jeton n'est rendu qu'ici, une fois ; la base n'en garde que l'empreinte.
     *
     * @param array{code: string, dbname: string}|null $site
     * @return array{url: string, expiresAt: string}
     */
    public function previewLink(?array $site, ?string $by): array
    {
        $site = $this->site($site);
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

    /** Ce que le visiteur d'un site en construction lit de la fiche du site : l'identité légale, les textes légaux, le logo. Rien d'autre */
    public const LEGAL_FIELDS = ['id', 'name', 'logo', 'faviconUrl', 'email', 'tel', 'ein', 'tvaIntracommunautaire', 'conditionOfUse', 'LegalNotice', 'privacyPolicy',
        'adress', 'street1', 'street2', 'city', 'zip', 'country', 'province', 'currency', 'locale'];

    /**
     * La fiche du site réduite à `LEGAL_FIELDS` : même forme (les fronts lisent les mêmes champs), tout le reste remis à sa valeur vide
     * (présentation, réseaux sociaux, référencement, pixel de suivi, modules).
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
     * @param array{code: string, dbname: string} $site
     * @return array{state: string, name: string, locale: string, openedAt: ?string, previewHash: ?string, previewExpiresAt: ?string}
     */
    private function load(array $site, ?\PDO $pdo = null): array
    {
        try {
            $row = ($pdo ?? $this->connector->pdo($site['dbname']))->query('SELECT name, locale, storefront_state, storefront_opened_at, storefront_preview_hash, storefront_preview_expires_at FROM entreprise ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('État public du site %s illisible (%s) : tenu pour ouvert.', $site['code'], $e::class));
            $row = false;
        }
        if (!$row) {
            return self::fallback($site['code'], (string) ($this->hosts->describe($site['code'])['name'] ?? $site['code']));
        }

        return ['state' => $row['storefront_state'] === self::CONSTRUCTION ? self::CONSTRUCTION : self::OPEN, 'name' => trim((string) $row['name']) !== '' ? (string) $row['name'] : $site['code'],
            'locale' => in_array($row['locale'], ['fr', 'en'], true) ? (string) $row['locale'] : 'fr', 'openedAt' => $row['storefront_opened_at'],
            'previewHash' => $row['storefront_preview_hash'], 'previewExpiresAt' => $row['storefront_preview_expires_at']];
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

    /** @return array{state: string, name: string, locale: string, openedAt: ?string, previewHash: ?string, previewExpiresAt: ?string} */
    private static function fallback(string $code, ?string $name = null): array
    {
        return ['state' => self::OPEN, 'name' => $name ?? $code, 'locale' => 'fr', 'openedAt' => null, 'previewHash' => null, 'previewExpiresAt' => null];
    }

    private static function iso(?string $at): ?string
    {
        return $at === null ? null : (new \DateTimeImmutable($at, new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
    }
}
