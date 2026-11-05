<?php

namespace App\Services\Platform;

use App\Repository\TenantAppKeyRepository;
use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\StripeService\StripeAccountResolver;
use App\Services\TenantConnectionManager;
use App\Services\TenantPublicHostResolver;
use App\Services\Platform\CustomDomainService;
use Psr\Log\LoggerInterface;

/**
 * Cheminement de préparation d'un site (console de pilotage, 05/11/2026) : une liste ordonnée d'étapes, calculée en SQL direct sur la
 * base du site (donc disponible pour le site courant comme pour la grille de la plateforme), chacune avec son état (`done`, `todo`,
 * `optional` pour une fonctionnalité non accordée, `pending` demande en cours), **où** elle se fait (`backend` : écran EasyAdmin,
 * `editor` : éditeur du site Next, `platform` : demande ou forfait) et son action (`screen` + clé d'écran, `registry` + clé du registre,
 * `editor` + url, `request` | `shop` + fonctionnalité). Le socle du site vient d'abord, puis les étapes de chaque module ou application
 * accordé ; les fonctionnalités non accordées apparaissent en fin, hors avancement. Le front et l'assistant IA lisent la même liste.
 */
class SiteReadinessService
{
    public function __construct(
        private readonly TenantConnectionManager $tenants,
        private readonly TenantDatabaseConnector $connector,
        private readonly EntitlementService $entitlements,
        private readonly FeatureRequestRepository $requests,
        private readonly FeaturePolicyRepository $policies,
        private readonly PlatformModeService $platform,
        private readonly TenantPublicHostResolver $hosts,
        private readonly TenantAppKeyRepository $appKeys,
        private readonly StripeAccountResolver $stripe,
        private readonly CustomDomainService $domains,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array<string, mixed> cheminement du site courant */
    public function forCurrentSite(): array
    {
        $code = (string) $this->tenants->getCurrentTenantCode();

        return $this->forSite($code);
    }

    /** @return array{code: string, percent: int, done: int, total: int, next: ?array<string, mixed>, steps: list<array<string, mixed>>} */
    public function forSite(string $code): array
    {
        $tenant = $this->connector->tenant($code);
        $empty = ['code' => $code, 'percent' => 0, 'done' => 0, 'total' => 0, 'next' => null, 'steps' => []];
        if ($tenant === null) {
            return $empty;
        }
        try {
            $pdo = $this->connector->pdo($tenant['dbname']);
            $steps = $this->build($code, $pdo);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('[Readiness] %s : cheminement illisible : %s', $code, $e->getMessage()));

            return $empty;
        }
        $counted = array_values(array_filter($steps, fn ($s) => in_array($s['status'], ['done', 'todo'], true)));
        $done = count(array_filter($counted, fn ($s) => $s['status'] === 'done'));
        $total = count($counted);
        $next = null;
        foreach ($steps as $s) {
            if ($s['status'] === 'todo') {
                $next = $s;
                break;
            }
        }

        return ['code' => $code, 'percent' => $total > 0 ? (int) round($done * 100 / $total) : 100, 'done' => $done, 'total' => $total, 'next' => $next, 'steps' => $steps];
    }

    /** @return list<array<string, mixed>> */
    private function build(string $code, \PDO $pdo): array
    {
        $e = $this->row($pdo, 'SELECT name, email, logo, locale, currency, pos_timezone FROM entreprise ORDER BY id LIMIT 1') ?? [];
        $address = $this->row($pdo, 'SELECT country, city FROM address_entreprise ORDER BY id LIMIT 1');
        $trials = [];
        foreach ($this->entitlements->listForTenant($code) as $row) {
            if ($row['source'] === 'trial') {
                $trials[$row['feature_key']] = true;
            }
        }
        $policies = $this->policies->all(array_keys(FeatureCatalog::GOVERNED));
        $fromEmail = trim((string) $this->one($pdo, 'SELECT from_email FROM email_configuration ORDER BY id LIMIT 1'));
        $commerce = $this->commerce($pdo);
        $effective = $this->entitlements->effective($code);
        $pending = $this->requests->pendingFor($code);
        $shopUrl = $this->platform->shopUrl();
        $host = $this->hosts->describe($code, true);
        $editorUrl = $host ? 'https://' . $host['host'] . '/' : null;
        $keys = $this->validKeys($code);
        $steps = [];
        $add = function (string $key, string $group, string $title, string $description, bool $done, string $where, array $action) use (&$steps) {
            $steps[] = ['key' => $key, 'group' => $group, 'title' => $title, 'description' => $description, 'status' => $done ? 'done' : 'todo', 'where' => $where, 'action' => $action];
        };

        // Socle du site
        $add('site.identity', 'site', 'Identité du site', 'Nom et courriel de contact sur la fiche Entreprise : ils signent vos courriels et vos reçus.', trim((string) ($e['name'] ?? '')) !== '' && trim((string) ($e['email'] ?? '')) !== '', 'backend', ['type' => 'screen', 'screen' => 'entreprise']);
        $add('site.address', 'site', 'Adresse du site', 'Pays, province et ville : ils déterminent la langue, le fuseau, les taxes et les frais de livraison.', $address !== null && trim((string) ($address['country'] ?? '')) !== '', 'backend', ['type' => 'screen', 'screen' => 'entreprise']);
        $add('site.localisation', 'site', 'Langue, fuseau et devise', 'Vérifiez la langue du site, le fuseau horaire et la devise (déduits de l\'adresse, modifiables).', $address !== null && trim((string) ($address['country'] ?? '')) !== '' && trim((string) ($e['currency'] ?? '')) !== '', 'editor', ['type' => 'registry', 'feature' => 'site.locale', 'url' => $editorUrl]);
        $add('site.email_sender', 'site', 'Adresse d\'envoi des courriels', 'L\'expéditeur de vos courriels (confirmation, reçus, codes) ; sans serveur propre, celui de la plateforme est utilisé.', $fromEmail !== '', 'backend', ['type' => 'screen', 'screen' => 'email_configuration']);
        $add('site.logo', 'site', 'Logo', 'Le logo apparaît sur le site, les courriels et les reçus.', trim((string) ($e['logo'] ?? '')) !== '', 'backend', ['type' => 'screen', 'screen' => 'entreprise']);
        $domain = $this->domains->status($code); // HTTPS gardé dix minutes : pas d'appel à chaque affichage
        if ($domain['state'] === 'none') {
            $steps[] = ['key' => 'site.domain', 'group' => 'site', 'title' => 'Domaine propre (facultatif)', 'description' => sprintf('Votre site répond déjà sur %s ; ajoutez votre propre domaine (achat chez un registraire, enregistrements DNS, certificat automatique).', $domain['platformHost']), 'status' => 'optional', 'where' => 'backend', 'action' => ['type' => 'domain']];
        } else {
            $add('site.domain', 'site', 'Domaine propre : ' . $domain['domain'], $domain['state'] === 'dns_pending' ? 'Le DNS ne pointe pas encore vers le serveur : créez les enregistrements chez votre registraire, puis vérifiez.' : ($domain['state'] === 'dns_ok' ? 'DNS en place ; le certificat HTTPS s\'émet automatiquement, vérifiez dans quelques minutes.' : 'Votre domaine sert le site en HTTPS.'), $domain['state'] === 'live', 'backend', ['type' => 'domain']);
        }

        // Modules et applications accordés
        if ($effective['module.landing']['enabled'] ?? false) {
            $add('landing.pages', 'module.landing', 'Pages du site', 'Composez vos pages dans l\'éditeur (modèles prêts à l\'emploi, assistant IA).', $this->count($pdo, 'landing_page_setting') > 0, 'editor', ['type' => 'editor', 'url' => $editorUrl]);
        }
        if ($effective['module.boutique']['enabled'] ?? false) {
            $taxOk = ($commerce['taxProvider'] ?? 'table') === 'stripe' || $this->count($pdo, 'tax') > 0;
            $add('boutique.taxes', 'module.boutique', 'Taxes', 'Au moins une taxe dans la table du site (TPS, TVQ…), ou Stripe Tax.', $taxOk, 'backend', ['type' => 'screen', 'screen' => 'taxes']);
            $mode = $this->stripe->mode(StripeAccountResolver::CHANNEL_SHOP);
            $stripeRow = $this->row($pdo, 'SELECT account_id, live_account_id FROM stripe_config ORDER BY id LIMIT 1');
            $stripeOk = $this->tenants->isTenantInternal($code) || trim((string) ($mode === StripeAccountResolver::LIVE ? ($stripeRow['live_account_id'] ?? '') : ($stripeRow['account_id'] ?? ''))) !== '';
            $add('boutique.stripe', 'module.boutique', 'Paiements en ligne (Stripe)', 'Compte Stripe connecté du site pour encaisser les commandes.', $stripeOk, 'backend', ['type' => 'screen', 'screen' => 'stripe_config']);
            $add('boutique.product', 'module.boutique', 'Premier produit', 'Créez au moins un produit (vente, location ou abonnement).', $this->count($pdo, 'product') > 0, 'backend', ['type' => 'screen', 'screen' => 'products']);
            $add('boutique.carrier', 'module.boutique', 'Livraison', 'Au moins un transporteur (prix fixe, gratuit ou EasyPost) pour expédier les commandes.', $this->count($pdo, 'carrier') > 0, 'backend', ['type' => 'screen', 'screen' => 'carriers']);
            if (($commerce['subscriptionsEnabled'] ?? false) === true) {
                $add('boutique.subscriptions', 'module.boutique', 'Abonnements', 'Au moins une formule d\'abonnement active et un transporteur à prix fixe ou gratuit.', $this->count($pdo, 'subscription_plan', 'active = TRUE') > 0 && $this->count($pdo, 'carrier', 'carrier_account_id IS NULL') > 0, 'backend', ['type' => 'screen', 'screen' => 'subscription_plans']);
            }
        }
        if ($effective['module.pos']['enabled'] ?? false) {
            $add('pos.key', 'module.pos', 'Clé d\'activation de la caisse', 'Générez une clé et saisissez-la dans l\'application Caisse.', in_array('pos', $keys, true), 'backend', ['type' => 'screen', 'screen' => 'app_keys']);
            $add('pos.register', 'module.pos', 'Poste de caisse', 'Créez au moins un poste de caisse (fond de caisse, terminal).', $this->count($pdo, 'cash_register', 'is_active = TRUE') > 0, 'backend', ['type' => 'screen', 'screen' => 'cash_registers']);
        }
        if ($effective['module.memoires']['enabled'] ?? false) {
            $add('memoires.book_type', 'module.memoires', 'Types de livre', 'Au moins un type de livre actif (fournis par le modèle, modifiables dans la console Mémoires Vivantes).', $this->count($pdo, 'mv_book_type', 'is_active = TRUE') > 0, 'editor', ['type' => 'editor', 'url' => $editorUrl]);
        }
        if ($effective['module.boussole']['enabled'] ?? false) {
            $add('boussole.questions', 'module.boussole', 'Questions du diagnostic', 'Au moins une question active (fournies par le modèle).', $this->count($pdo, 'esg_diag_question', 'is_active = TRUE') > 0, 'backend', ['type' => 'screen', 'screen' => 'esg_questions']);
        }
        if ($effective['app.mobile_shop']['enabled'] ?? false) {
            $add('mobile_shop.key', 'app.mobile_shop', 'Clé de l\'application e-commerce', 'Générez la clé d\'activation de l\'application mobile.', in_array('mobile', $keys, true), 'backend', ['type' => 'screen', 'screen' => 'app_keys']);
            $add('mobile_shop.short_code', 'app.mobile_shop', 'Code court de la boutique', 'Le code que vos clients tapent dans l\'application.', !empty($host['shortCode']), 'backend', ['type' => 'screen', 'screen' => 'short_code']);
        }
        if ($effective['app.management']['enabled'] ?? false) {
            $add('management.key', 'app.management', 'Clé de l\'application de gestion', 'Générez la clé d\'activation et saisissez-la dans l\'application de gestion.', in_array('admin', $keys, true), 'backend', ['type' => 'screen', 'screen' => 'app_keys']);
        }
        if ($effective['app.living_memory']['enabled'] ?? false) {
            $add('living_memory.key', 'app.living_memory', 'Clé de l\'application Living Memory', 'Générez la clé d\'activation portée par l\'application pour votre site.', in_array('livingmemory', $keys, true), 'backend', ['type' => 'screen', 'screen' => 'app_keys']);
        }

        // Fonctionnalités non accordées : à obtenir (hors avancement)
        foreach (array_keys(FeatureCatalog::GOVERNED) as $feature) {
            if ($effective[$feature]['enabled'] ?? false) {
                continue;
            }
            $isPending = isset($pending[$feature]);
            $trial = ($policies[$feature]['trial_allowed'] ?? false) && !isset($trials[$feature]);
            $steps[] = [
                'key' => 'obtain.' . $feature, 'group' => $feature, 'title' => 'Obtenir : ' . FeatureCatalog::label($feature), 'description' => FeatureCatalog::DESCRIPTIONS[$feature] ?? '',
                'status' => $isPending ? 'pending' : 'optional', 'where' => 'platform',
                // `trial` : POST /api/site/features/{clé}/request ouvre un essai gratuit immédiat ; `request` : même route, demande à la plateforme ; `shop` : boutique de forfaits
                'action' => $trial ? ['type' => 'trial', 'feature' => $feature, 'days' => (int) $policies[$feature]['trial_days'], 'shopUrl' => $shopUrl] : ($shopUrl !== null ? ['type' => 'shop', 'feature' => $feature, 'url' => $shopUrl] : ['type' => 'request', 'feature' => $feature]),
            ];
        }

        return $steps;
    }

    /** @return array<string, mixed> clés commerce des réglages de la boutique */
    private function commerce(\PDO $pdo): array
    {
        $raw = $this->one($pdo, 'SELECT configuration::text FROM boutique_setting ORDER BY id LIMIT 1');
        $conf = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($conf['commerce'] ?? null) ? $conf['commerce'] : [];
    }

    /** @return list<string> applications ayant une clé valide */
    private function validKeys(string $code): array
    {
        $apps = [];
        try {
            foreach ($this->appKeys->listForTenant($code) as $k) {
                $expired = !empty($k['expires_at']) && strtotime((string) $k['expires_at']) < time();
                if (empty($k['revoked_at']) && !$expired) {
                    $apps[] = (string) $k['app'];
                }
            }
        } catch (\Throwable) {
        }

        return array_values(array_unique($apps));
    }

    private function count(\PDO $pdo, string $table, string $where = 'TRUE'): int
    {
        try {
            return (int) $pdo->query("SELECT COUNT(*) FROM $table WHERE $where")->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function one(\PDO $pdo, string $sql): mixed
    {
        try {
            $v = $pdo->query($sql)->fetchColumn();

            return $v === false ? null : $v;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function row(\PDO $pdo, string $sql): ?array
    {
        try {
            $r = $pdo->query($sql)->fetch(\PDO::FETCH_ASSOC);

            return $r ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
