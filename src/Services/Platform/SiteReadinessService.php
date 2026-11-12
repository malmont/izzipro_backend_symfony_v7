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
    /**
     * Pages publiques des produits sur le site Next, relatives à l'hôte public du site (08/11/2026) : liens « Voir le résultat » du tableau
     * « Préparer mes produits » et de `GET /api/site/readiness` (`links`). La landing page est à `/landingpage` (clé `site`, Next sert l'accueil)
     * et chaque autre onglet à `/landingpage/<slug>` ; un module n'a son lien que s'il est accordé.
     */
    public const PUBLIC_PATHS = ['module.boutique' => '/catalogue', 'module.memoires' => '/memoires', 'module.boussole' => '/boussole'];

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
        private readonly ?\App\Services\Help\HelpCatalogService $help = null, // aide intégrée (07/11/2026)
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
        $empty = ['code' => $code, 'percent' => 0, 'done' => 0, 'total' => 0, 'next' => null, 'steps' => [], 'links' => null];
        if ($tenant === null) {
            return $empty;
        }
        try {
            $pdo = $this->connector->pdo($tenant['dbname']);
            ['steps' => $steps, 'links' => $links] = $this->build($code, $pdo);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('[Readiness] %s : cheminement illisible : %s', $code, $e->getMessage()));

            return $empty;
        }
        foreach ($steps as $i => $s) {
            $steps[$i]['helpKey'] = $this->help?->forStep($s['key']) ?? (isset($s['action']['screen']) ? $this->help?->forScreen($s['action']['screen']) : null); // page d'aide (07/11/2026)
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

        // Cheminement complet (07/11/2026) : à faire indispensable (rouge) / amélioration (orange), par produit
        $blockingTodo = count(array_filter($steps, fn ($s) => $s['status'] === 'todo' && ($s['blocking'] ?? true)));
        $improvementTodo = count(array_filter($steps, fn ($s) => ($s['status'] === 'todo' && !($s['blocking'] ?? true)) || $s['status'] === 'optional'));
        $groups = [];
        foreach ($steps as $s) {
            if (str_starts_with($s['key'], 'obtain.')) {
                continue;
            }
            $g = $s['group'];
            $groups[$g] ??= ['key' => $g, 'label' => self::groupLabel($g), 'blockingTodo' => 0, 'improvementTodo' => 0, 'done' => 0, 'pending' => 0, 'total' => 0];
            $groups[$g]['total']++;
            if ($s['status'] === 'done') {
                $groups[$g]['done']++;
            } elseif ($s['status'] === 'pending') {
                $groups[$g]['pending']++;
            } elseif ($s['status'] === 'todo' && ($s['blocking'] ?? true)) {
                $groups[$g]['blockingTodo']++;
            } else {
                $groups[$g]['improvementTodo']++;
            }
        }
        foreach ($groups as $g => $v) {
            $groups[$g]['ready'] = $v['blockingTodo'] === 0;
        }

        return ['code' => $code, 'percent' => $total > 0 ? (int) round($done * 100 / $total) : 100, 'done' => $done, 'total' => $total, 'next' => $next, 'steps' => $steps,
            'blockingTodo' => $blockingTodo, 'improvementTodo' => $improvementTodo, 'groups' => array_values($groups), 'links' => $links];
    }

    /**
     * Cheminement de l'application de gestion (lot CHE, 12/11/2026, contrat validé par l'application) : bloquantes = ce sans quoi l'application ne
     * sert pas (clé, code court, administrateur, période en cours, exercice, devise et fuseau) ; améliorations = ce qui rend les chiffres justes
     * (coût d'achat, fournisseurs, référentiels, trésorerie, frais fixes, IA, appareil, premier achat). Chaque étape porte en plus `appScreen` (code
     * d'écran de l'application, même vocabulaire que les vignettes ; null = à faire sur le site) et `figures` (comptes) ; les chiffres utiles sont
     * aussi dans `description`, que l'application affiche telle quelle.
     *
     * @param list<array<string, mixed>> $steps
     * @param array<string, mixed> $e fiche Entreprise (colonnes lues par build)
     */
    private function managementSteps(array &$steps, \PDO $pdo, array $e, ?array $address, array $keys, ?array $host, array $effective): void
    {
        $add = function (string $key, string $title, string $description, bool $done, array $action, bool $blocking, ?string $appScreen, ?array $figures = null) use (&$steps) {
            $steps[] = ['key' => $key, 'group' => 'app.management', 'title' => $title, 'description' => $description, 'status' => $done ? 'done' : 'todo', 'where' => 'backend',
                'action' => $action, 'blocking' => $blocking, 'appScreen' => $appScreen] + ($figures !== null ? ['figures' => $figures] : []);
        };
        $gestion = $this->row($pdo, 'SELECT purchase_period_frequency, fiscal_year_start_month FROM entreprise ORDER BY id LIMIT 1') ?? [];
        $screen = fn (string $s) => ['type' => 'screen', 'screen' => $s];
        // Bloquantes
        // Pas d'étape « clé d'activation » (retirée le 12/11/2026) : l'application trouve le site par son code court puis se connecte avec un
        // compte administrateur ; la clé IZ-ADM reste facultative (elle permet de couper l'accès d'un appareil).
        $add('management.short_code', 'Code court du site', 'Le code que l\'application demande à la première connexion pour trouver votre site.', !empty($host['shortCode']), $screen('short_code'), true, null);
        $admins = $this->count($pdo, '"user"', "roles::text LIKE '%ROLE_ADMIN%'");
        $add('management.admin', 'Un compte administrateur', $admins > 0 ? sprintf('%d compte(s) administrateur peuvent se connecter à l\'application de gestion.', $admins) : 'Créez un compte administrateur : seul ce rôle se connecte à l\'application de gestion.', $admins > 0, $screen('users'), true, null, ['admins' => $admins]);
        $current = $this->count($pdo, 'collections', "kind = 'period' AND COALESCE(del, false) = false AND start_date_collection <= NOW() AND end_date_collection >= CURRENT_DATE");
        $add('management.period', 'Période de gestion en cours', $current > 0 ? 'La période en cours existe : achats, notes de frais et résultat s\'y rangent.' : 'Ouvrez l\'application de gestion : la période en cours est créée automatiquement selon le calendrier du site.', $current > 0, ['type' => 'registry', 'feature' => 'gestion.periods'], true, 'periodes');
        $month = $gestion['fiscal_year_start_month'] ?? null;
        $add('management.fiscal_year', 'Mois de début d\'exercice', $month !== null ? sprintf('Exercice qui commence en %s : il sert au bilan de l\'année.', ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'][(int) $month] ?? $month) : 'Choisissez le mois de début de votre exercice (écran Exercice de l\'application) : il sert au bilan de l\'année.', $month !== null, $screen('gestion'), true, 'exercice');
        $localeOk = trim((string) ($e['currency'] ?? '')) !== '' && (trim((string) ($e['pos_timezone'] ?? '')) !== '' || trim((string) ($address['country'] ?? '')) !== '');
        $add('management.locale', 'Devise et fuseau du site', 'Montants dans la devise du site et jours comptés au fuseau du magasin (déduit de l\'adresse, sinon réglé sur la fiche).', $localeOk, ['type' => 'registry', 'feature' => 'site.locale'], true, null);
        // Améliorations
        $add('management.periods_calendar', 'Calendrier des périodes à confirmer', $gestion['purchase_period_frequency'] ?? null ? 'Calendrier des périodes choisi.' : 'Trimestres par défaut : confirmez-les, ou choisissez des semestres ou des mois.', ($gestion['purchase_period_frequency'] ?? null) !== null, ['type' => 'registry', 'feature' => 'gestion.periods'], false, 'periodes');
        $articles = "product p WHERE p.product_type = 'standard' AND COALESCE(p.rental_enabled, false) = false";
        $total = (int) $this->one($pdo, "SELECT COUNT(*) FROM $articles");
        $missing = (int) $this->one($pdo, "SELECT COUNT(*) FROM $articles AND (p.purchase_price IS NULL OR p.purchase_price <= 0) AND NOT EXISTS (SELECT 1 FROM commande_line l WHERE l.product_id = p.id AND l.unit_cost_cents IS NOT NULL)");
        $add('management.purchase_cost', 'Coût d\'achat des articles', $missing > 0 ? sprintf('%d article(s) sur %d sans coût d\'achat : leur marge reste inconnue.', $missing, $total) : ($total > 0 ? sprintf('Les %d articles ont un coût d\'achat : marges connues.', $total) : 'Aucun article vendu pour l\'instant.'), $missing === 0, $screen('products'), false, 'articles', ['missing' => $missing, 'total' => $total]);
        $suppliers = $this->count($pdo, 'fournisseur');
        $add('management.suppliers', 'Fournisseurs', $suppliers > 0 ? sprintf('%d fournisseur(s) enregistré(s).', $suppliers) : 'Ajoutez vos fournisseurs : ils servent aux commandes et à la lecture des factures.', $suppliers > 0, $screen('fournisseurs'), false, 'fournisseurs', ['suppliers' => $suppliers]);
        $types = $this->count($pdo, 'type_fournisseur') > 0 && $this->count($pdo, 'type_note_de_frais') > 0;
        $add('management.referentials', 'Types de fournisseur et de note de frais', 'Les listes qui classent vos fournisseurs et vos notes de frais (fournies par le modèle, modifiables).', $types, $screen('type_fournisseurs'), false, null);
        $cash = $this->count($pdo, 'gestion_cash_balance') > 0 && $this->count($pdo, 'gestion_cash_setting', 'threshold_cents IS NOT NULL') > 0;
        $add('management.cash', 'Trésorerie : solde de départ et seuil d\'alerte', 'Un solde bancaire daté et un seuil : la projection de trésorerie et l\'alerte de trésorerie basse s\'en servent.', $cash, $screen('gestion'), false, 'tresorerie');
        $fixed = $this->count($pdo, 'gestion_cash_movement', 'fixed_cost = true');
        $add('management.fixed_costs', 'Frais fixes', $fixed > 0 ? sprintf('%d frais fixe(s) saisi(s) : le résultat après frais fixes est calculé.', $fixed) : 'Saisissez vos frais fixes (loyer, salaires, abonnements) pour voir le résultat après frais fixes.', $fixed > 0, $screen('gestion'), false, 'frais-fixes', ['fixedCosts' => $fixed]);
        $tier = ($effective['ai.full']['enabled'] ?? false) ? 'full' : (($effective['ai.help']['enabled'] ?? false) ? 'help' : 'none');
        $monthly = (int) ($this->one($pdo, 'SELECT monthly_credits FROM ai_credit_setting ORDER BY id LIMIT 1') ?? 0) ?: $this->platform->aiDefaultCredits();
        $monthStart = (new \DateTimeImmutable('first day of this month 00:00:00', new \DateTimeZone('America/Toronto')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $used = (int) $this->one($pdo, sprintf("SELECT COALESCE(SUM(credits), 0) FROM ai_usage WHERE created_at >= '%s' AND (status = 'success' OR (status = 'reserved' AND reserved_until >= NOW()))", $monthStart));
        $remaining = max(0, $monthly - $used);
        $add('management.ai', 'Assistant IA de la gestion', $tier === 'none' ? 'Sans palier IA, le guide et les avis restent calculés par les règles (sans texte rédigé).' : sprintf('%d crédit(s) IA restant(s) sur %d ce mois-ci (1 par conseil, avis ou document lu).', $remaining, $monthly), $tier !== 'none' && $remaining > 0, $screen('ai_credits'), false, null, ['tier' => $tier, 'remaining' => $remaining, 'monthly' => $monthly]);
        $devices = $this->count($pdo, 'push_device', 'disabled_at IS NULL');
        $add('management.device', 'Appareil pour les notifications', $devices > 0 ? sprintf('%d appareil(s) reçoivent les notifications.', $devices) : 'Autorisez les notifications dans l\'application : stock bas, trésorerie basse, facture en double.', $devices > 0, $screen('gestion'), false, 'notifications', ['devices' => $devices]);
        $purchases = $this->count($pdo, 'commande') + $this->count($pdo, 'gestion_reprise');
        $add('management.first_purchase', 'Premier achat ou reprise d\'historique', $purchases > 0 ? 'Des achats sont saisis : coûts, marges et stock se calculent.' : 'Saisissez une première commande fournisseur, ou reprenez l\'historique de vos ventes passées.', $purchases > 0, $screen('gestion'), false, 'commandes');
    }

    /** Libellé d'un groupe d'étapes : produit (module ou application), socle du site, assistant IA */
    public static function groupLabel(string $group): string
    {
        return ['site' => 'Site : identité et socle', 'ai' => 'Assistant IA'][$group] ?? (FeatureCatalog::exists($group) ? FeatureCatalog::label($group) : $group);
    }

    /** @return array{steps: list<array<string, mixed>>, links: array<string, mixed>} */
    private function build(string $code, \PDO $pdo): array
    {
        $e = $this->row($pdo, 'SELECT name, email, logo, locale, currency, pos_timezone, tel, favicon_filename, meta_title, meta_description, gst_number, qst_number, siret, tva_intracommunautaire, pos_receipt_header, pos_alerts_email, pos_stripe_mode, data_retention_years FROM entreprise ORDER BY id LIMIT 1') ?? [];
        $address = $this->row($pdo, 'SELECT country, city, state FROM address_entreprise ORDER BY id LIMIT 1');
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
        // `blocking` (cheminement complet, 07/11/2026) : vrai = indispensable au fonctionnement du produit (rouge), faux = amélioration (orange)
        $add = function (string $key, string $group, string $title, string $description, bool $done, string $where, array $action, bool $blocking = true) use (&$steps) {
            $steps[] = ['key' => $key, 'group' => $group, 'title' => $title, 'description' => $description, 'status' => $done ? 'done' : 'todo', 'where' => $where, 'action' => $action, 'blocking' => $blocking];
        };
        $boutiqueOn = (bool) ($effective['module.boutique']['enabled'] ?? false);
        $posOn = (bool) ($effective['module.pos']['enabled'] ?? false);
        $selling = $boutiqueOn || $posOn; // vendre (en ligne ou au comptoir) rend les textes légaux et les numéros de taxe indispensables
        $country = strtoupper(trim((string) ($address['country'] ?? '')));
        $country = ['CANADA' => 'CA', 'FRANCE' => 'FR'][$country] ?? substr($country, 0, 2);
        $province = strtoupper(trim((string) ($address['state'] ?? '')));
        $filled = fn (?string $v) => trim((string) $v) !== '';

        // Socle du site
        $add('site.identity', 'site', 'Identité du site', 'Nom et courriel de contact sur la fiche Entreprise : ils signent vos courriels et vos reçus.', trim((string) ($e['name'] ?? '')) !== '' && trim((string) ($e['email'] ?? '')) !== '', 'backend', ['type' => 'screen', 'screen' => 'entreprise']);
        $add('site.address', 'site', 'Adresse du site', 'Pays, province et ville : ils déterminent la langue, le fuseau, les taxes et les frais de livraison.', $address !== null && trim((string) ($address['country'] ?? '')) !== '', 'backend', ['type' => 'screen', 'screen' => 'entreprise']);
        $add('site.localisation', 'site', 'Langue, fuseau et devise', 'Vérifiez la langue du site, le fuseau horaire et la devise (déduits de l\'adresse, modifiables).', $address !== null && trim((string) ($address['country'] ?? '')) !== '' && trim((string) ($e['currency'] ?? '')) !== '', 'editor', ['type' => 'registry', 'feature' => 'site.locale', 'url' => $editorUrl]);
        $add('site.email_sender', 'site', 'Adresse d\'envoi des courriels', 'L\'expéditeur de vos courriels (confirmation, reçus, codes) ; sans serveur propre, celui de la plateforme est utilisé.', $fromEmail !== '', 'backend', ['type' => 'screen', 'screen' => 'configuration'], false); // cheminement Courriels (lot 5, 07/11/2026) ; amélioration : la plateforme envoie sinon
        $add('site.logo', 'site', 'Logo', 'Le logo apparaît sur le site, les courriels et les reçus.', trim((string) ($e['logo'] ?? '')) !== '', 'backend', ['type' => 'screen', 'screen' => 'entreprise'], false);
        // Cheminement complet (07/11/2026) : ce qu'une entreprise doit avoir pour fonctionner et vendre
        $add('site.phone', 'site', 'Téléphone de l\'entreprise', 'Sur les reçus, les tickets et les étiquettes (certains transporteurs l\'exigent) ; vos clients savent qui appeler.', $filled($e['tel'] ?? null), 'backend', ['type' => 'screen', 'screen' => 'entreprise'], false);
        $legal = ['legal_notice' => 'mentions légales', 'condition_of_use' => 'conditions générales (CGV / CGU)', 'privacy_policy' => 'politique de confidentialité'];
        $missingLegal = [];
        foreach ($legal as $column => $label) {
            if ($this->count($pdo, 'entreprise_translation', "COALESCE($column, '') <> ''") === 0) {
                $missingLegal[] = $label;
            }
        }
        $add('site.legal', 'site', 'Textes légaux', $missingLegal === [] ? 'Mentions légales, conditions générales et politique de confidentialité sont rédigées (fiche Entreprise, onglet des textes).' : sprintf('Manque : %s. Obligatoires pour vendre en ligne ou au comptoir (loi) ; l\'assistant IA peut rédiger un premier jet à partir de votre fiche.', implode(', ', $missingLegal)), $missingLegal === [], 'backend', ['type' => 'screen', 'screen' => 'entreprise'], $selling);
        if ($country === 'CA') {
            $taxOk = $filled($e['gst_number'] ?? null) && ($province !== 'QC' || $filled($e['qst_number'] ?? null));
            $taxText = $province === 'QC' ? 'Numéros de TPS et de TVQ : obligatoires sur les reçus et factures (fiche Entreprise, panneau Caisse).' : 'Numéro de TPS/TVH : obligatoire sur les reçus et factures (fiche Entreprise, panneau Caisse).';
        } elseif ($country === 'FR') {
            $taxOk = $filled($e['siret'] ?? null);
            $taxText = 'SIRET et numéro de TVA intracommunautaire : obligatoires sur les factures (fiche Entreprise).';
        } else {
            $taxOk = $filled($e['gst_number'] ?? null) || $filled($e['siret'] ?? null) || $filled($e['tva_intracommunautaire'] ?? null);
            $taxText = 'Numéro d\'identification fiscale de l\'entreprise sur les reçus et factures (fiche Entreprise).';
        }
        $add('site.tax_numbers', 'site', 'Numéros de taxe de l\'entreprise', $taxText, $taxOk, 'backend', ['type' => 'screen', 'screen' => 'entreprise'], $selling);
        $add('site.favicon', 'site', 'Icône du site (favicon)', 'La petite icône de l\'onglet du navigateur et de l\'écran d\'accueil mobile.', $filled($e['favicon_filename'] ?? null), 'backend', ['type' => 'screen', 'screen' => 'entreprise'], false);
        $add('site.seo', 'site', 'Référencement : titre et description', 'Le titre et la description que Google affiche ; sans eux, le site est mal présenté dans les résultats.', $filled($e['meta_title'] ?? null) && $filled($e['meta_description'] ?? null), 'backend', ['type' => 'screen', 'screen' => 'entreprise'], false);
        $add('site.social', 'site', 'Réseaux sociaux', 'Liens affichés sur le site et dans les courriels.', $this->count($pdo, 'social_network') > 0, 'backend', ['type' => 'screen', 'screen' => 'social_networks'], false);
        // Charte et brief du site (08/11/2026) : questionnaire « Mon site pas à pas » ; table absente sur une base non migrée = rien
        $siteCharter = null;
        try {
            $siteCharter = $this->row($pdo, 'SELECT brief, pages_saved_at, composed_at FROM site_charter ORDER BY id LIMIT 1');
        } catch (\Throwable) {
        }
        $add('site.brief', 'site', 'Mon site pas à pas : brief et charte', 'Dites ce que fait le site, pour qui, avec quelles pages et quelles couleurs : l\'assistant compose ensuite le site, et l\'éditeur comme l\'assistant suivent cette charte.', is_string($siteCharter['brief'] ?? null) && $siteCharter['brief'] !== '' && $siteCharter['brief'] !== 'null', 'backend', ['type' => 'screen', 'screen' => 'site_setup'], false);
        $domain = $this->domains->status($code); // HTTPS gardé dix minutes : pas d'appel à chaque affichage
        if ($domain['state'] === 'none') {
            $steps[] = ['key' => 'site.domain', 'group' => 'site', 'title' => 'Domaine propre (facultatif)', 'description' => sprintf('Votre site répond déjà sur %s ; ajoutez votre propre domaine (achat chez un registraire, enregistrements DNS, certificat automatique).', $domain['platformHost']), 'status' => 'optional', 'where' => 'backend', 'action' => ['type' => 'domain'], 'blocking' => false];
        } else {
            $add('site.domain', 'site', 'Domaine propre : ' . $domain['domain'], $domain['state'] === 'dns_pending' ? 'Le DNS ne pointe pas encore vers le serveur : créez les enregistrements chez votre registraire, puis vérifiez.' : ($domain['state'] === 'dns_ok' ? 'DNS en place ; le certificat HTTPS s\'émet automatiquement, vérifiez dans quelques minutes.' : 'Votre domaine sert le site en HTTPS.'), $domain['state'] === 'live', 'backend', ['type' => 'domain'], false);
        }

        // Compte Stripe du site (boutique, abonnements, caisse) : lu une fois
        $mode = $this->stripe->mode(StripeAccountResolver::CHANNEL_SHOP);
        $stripeRow = $this->row($pdo, 'SELECT account_id, live_account_id FROM stripe_config ORDER BY id LIMIT 1');
        $stripeOk = $this->tenants->isTenantInternal($code) || trim((string) ($mode === StripeAccountResolver::LIVE ? ($stripeRow['live_account_id'] ?? '') : ($stripeRow['account_id'] ?? ''))) !== '';

        // Modules et applications accordés
        if ($effective['module.landing']['enabled'] ?? false) {
            // Pages du site (08/11/2026) : faites dès que les pages ont été enregistrées (éditeur) ou composées (« Mon site pas à pas ») ; le contenu d'exemple cloné ne compte pas
            $pagesDone = !empty($siteCharter['pages_saved_at']) || !empty($siteCharter['composed_at']);
            $add('landing.pages', 'module.landing', 'Pages du site', 'Laissez l\'assistant composer le site depuis « Mon site pas à pas », ou composez vos pages dans l\'éditeur du site ; tant que rien n\'est enregistré, le site montre le contenu d\'exemple.', $pagesDone, 'backend', ['type' => 'screen', 'screen' => 'site_setup']);
        }
        if ($effective['module.boutique']['enabled'] ?? false) {
            $taxOk = ($commerce['taxProvider'] ?? 'table') === 'stripe' || $this->count($pdo, 'tax') > 0;
            $add('boutique.taxes', 'module.boutique', 'Taxes', 'Au moins une taxe dans la table du site (TPS, TVQ…), ou Stripe Tax.', $taxOk, 'backend', ['type' => 'screen', 'screen' => 'configuration']); // cheminement Taxes (lot 5)
            $add('boutique.stripe', 'module.boutique', 'Paiements en ligne (Stripe)', 'Compte Stripe connecté du site pour encaisser les commandes.', $stripeOk, 'backend', ['type' => 'screen', 'screen' => 'configuration']); // cheminement Stripe (lot 5)
            $sellable = $this->count($pdo, 'product p', 'EXISTS (SELECT 1 FROM product_variant v WHERE v.product_id = p.id AND v.price > 0)');
            $add('boutique.product', 'module.boutique', 'Premier produit vendable', $sellable > 0 ? sprintf('%d produit(s) avec un prix.', $sellable) : 'Créez au moins un produit avec une variante et un prix supérieur à zéro (vente, location ou abonnement).', $sellable > 0, 'backend', ['type' => 'screen', 'screen' => 'products']);
            $products = $this->count($pdo, 'product');
            $noPicture = $this->count($pdo, 'product p', 'NOT EXISTS (SELECT 1 FROM product_picture pp WHERE pp.product_id = p.id)');
            $add('boutique.pictures', 'module.boutique', 'Photos des produits', $products > 0 && $noPicture === 0 ? 'Tous vos produits ont au moins une photo.' : sprintf('%d produit(s) sans photo : un produit sans image se vend mal.', $noPicture), $products > 0 && $noPicture === 0, 'backend', ['type' => 'screen', 'screen' => 'products'], false);
            $add('boutique.categories', 'module.boutique', 'Catégories du catalogue', 'Au moins une catégorie visible : vos clients naviguent par catégorie.', $this->count($pdo, 'categories', 'COALESCE(is_visible, TRUE) = TRUE') > 0, 'backend', ['type' => 'screen', 'screen' => 'categories'], false);
            // Produits en ligne incomplets (lots IA, 07/11/2026) : fiche, traduction, image mannequin
            $online = $this->count($pdo, 'product', 'is_web = TRUE');
            if ($online > 0) {
                $noSheet = $this->count($pdo, 'product', "is_web = TRUE AND LENGTH(TRIM(COALESCE(regexp_replace(description, '<[^>]*>', '', 'g'), ''))) < 40");
                $add('boutique.sheets', 'module.boutique', 'Fiches produit complètes', $noSheet === 0 ? 'Tous les produits en ligne ont une description.' : sprintf('%d produit(s) en ligne sans description (ou trop courte) : « IA en lot » les rédige en un clic depuis leurs photos.', $noSheet), $noSheet === 0, 'backend', ['type' => 'screen', 'screen' => 'product_ai_bulk'], false);
                $other = ($e['locale'] ?? 'fr') === 'en' ? 'fr' : 'en';
                $noTranslation = $this->count($pdo, 'product p', sprintf("p.is_web = TRUE AND NOT EXISTS (SELECT 1 FROM product_translation t WHERE t.product_id = p.id AND t.locale = '%s' AND COALESCE(t.name, '') <> '' AND LENGTH(TRIM(COALESCE(regexp_replace(t.description, '<[^>]*>', '', 'g'), ''))) > 0)", $other));
                $add('boutique.translations', 'module.boutique', 'Fiches traduites', $noTranslation === 0 ? sprintf('Tous les produits en ligne sont traduits en %s.', $other) : sprintf('%d produit(s) en ligne sans traduction en %s : « IA en lot » les traduit en un clic.', $noTranslation, $other), $noTranslation === 0, 'backend', ['type' => 'screen', 'screen' => 'product_ai_bulk'], false);
                $noAiImage = $this->count($pdo, 'product p', "p.is_web = TRUE AND NOT EXISTS (SELECT 1 FROM product_picture pp WHERE pp.product_id = p.id AND pp.source = 'ai')");
                $add('boutique.ai_images', 'module.boutique', 'Images mannequin', $noAiImage === 0 ? 'Tous les produits en ligne ont une image générée.' : sprintf('%d produit(s) en ligne sans image mannequin générée par l\'IA (vêtements : une personne portant l\'article). Disponible dès que la plateforme branche son fournisseur d\'images.', $noAiImage), $noAiImage === 0, 'backend', ['type' => 'screen', 'screen' => 'product_ai_bulk'], false);
            }
            $add('boutique.reviews', 'module.boutique', 'Avis clients', 'Réglages des avis et politique affichée (page Configuration, Avis clients).', $this->count($pdo, 'review_setting', "policy IS NOT NULL AND policy::text <> 'null' AND policy::text <> '{}'") > 0, 'backend', ['type' => 'screen', 'screen' => 'configuration'], false);
            if (in_array('rental', (array) ($commerce['salesModes'] ?? ['sale', 'rental', 'subscription']), true)) {
                // Location (cheminement complet, 07/11/2026) : un produit louable a besoin d'une configuration de réservation et d'un tarif (pack lié à sa catégorie)
                $rentable = $this->count($pdo, 'product', 'rental_enabled = TRUE');
                $configured = $this->count($pdo, 'product p', 'p.rental_enabled = TRUE AND EXISTS (SELECT 1 FROM booking_configuration bc WHERE bc.product_id = p.id AND bc.granularity IS NOT NULL)');
                $priced = $this->count($pdo, 'product p', 'p.rental_enabled = TRUE AND EXISTS (SELECT 1 FROM product_categories pc JOIN rental_pack_categories rpc ON rpc.categories_id = pc.categories_id JOIN rental_pack rp ON rp.id = rpc.rental_pack_id WHERE pc.product_id = p.id AND COALESCE(rp.day_rate, rp.hour_rate, rp.half_day_rate, rp.week_rate, rp.month_rate, 0) > 0)');
                $add('boutique.rental_product', 'module.boutique', 'Location : un produit louable', $rentable > 0 ? sprintf('%d produit(s) proposé(s) à la location.', $rentable) : 'Le mode location est activé mais aucun produit n\'est louable : cochez « Location » sur la fiche d\'un produit, ou retirez ce mode de vente (Mon site, Commerce).', $rentable > 0, 'backend', ['type' => 'screen', 'screen' => 'rental']);
                $add('boutique.rental_booking', 'module.boutique', 'Location : configuration de réservation', $rentable > 0 && $configured === $rentable ? 'Chaque produit louable a sa granularité (heure, jour…), son stock et ses durées.' : sprintf('%d produit(s) louable(s) sans configuration de réservation (granularité, stock, durée minimale, horaires) : sans elle, aucun créneau ne peut être réservé.', max(0, $rentable - $configured)), $rentable > 0 && $configured === $rentable, 'backend', ['type' => 'screen', 'screen' => 'rental']);
                $add('boutique.rental_rates', 'module.boutique', 'Location : tarifs', $rentable > 0 && $priced === $rentable ? 'Chaque produit louable a un tarif (pack de location lié à sa catégorie).' : sprintf('%d produit(s) louable(s) sans tarif : créez un pack de location (tarif à l\'heure, à la journée…) et rattachez-le à la catégorie du produit.', max(0, $rentable - $priced)), $rentable > 0 && $priced === $rentable, 'backend', ['type' => 'screen', 'screen' => 'rental']);
                $add('boutique.rental_deposit', 'module.boutique', 'Location : caution et conditions d\'annulation', 'Caution et politique d\'annulation sur la configuration de réservation : recommandé pour les biens de valeur.', $rentable > 0 && $this->count($pdo, 'booking_configuration', 'deposit > 0 OR COALESCE(cancellation_policy, \'\') <> \'\'') > 0, 'backend', ['type' => 'screen', 'screen' => 'rental'], false);
            }
            $add('boutique.carrier', 'module.boutique', 'Livraison', 'Au moins un mode de remise (transporteur à prix fixe, retrait en magasin ou EasyPost) ; vous expédiez vous-même en saisissant le suivi sur la commande. Réglages et cheminement : page Livraison.', $this->count($pdo, 'carrier') > 0, 'backend', ['type' => 'screen', 'screen' => 'shipping']);
            // Livraison, lot 1 (07/11/2026) : poids des produits exigé seulement quand le site estime ou achète des étiquettes (mode own_key ou platform)
            if (in_array($commerce['shippingMode'] ?? 'none', ['own_key', 'platform'], true)) {
                $withoutWeight = $this->count($pdo, 'product p', 'p.requires_shipping = TRUE AND NOT EXISTS (SELECT 1 FROM product_shipping ps WHERE ps.product_id = p.id AND ps.weight > 0)');
                $add('boutique.shipping_weights', 'module.boutique', 'Poids et dimensions des produits', sprintf('%d produit(s) à expédier sans poids : indispensable pour estimer un tarif ou acheter une étiquette.', $withoutWeight), $withoutWeight === 0, 'backend', ['type' => 'screen', 'screen' => 'product_weights']);
            }
            if (($commerce['subscriptionsEnabled'] ?? false) === true) {
                $add('boutique.subscriptions', 'module.boutique', 'Abonnements', 'Au moins une formule d\'abonnement active et un transporteur à prix fixe ou gratuit.', $this->count($pdo, 'subscription_plan', 'active = TRUE') > 0 && $this->count($pdo, 'carrier', 'carrier_account_id IS NULL') > 0, 'backend', ['type' => 'screen', 'screen' => 'subscription_plans']);
                $add('boutique.subscriptions_stripe', 'module.boutique', 'Abonnements : compte Stripe prêt', 'Les abonnements sont facturés par Stripe Billing sur votre compte connecté : le compte doit être connecté et vérifié (page Configuration, Paiements).', $stripeOk, 'backend', ['type' => 'screen', 'screen' => 'configuration']);
            }
        }
        if ($effective['module.pos']['enabled'] ?? false) {
            $add('pos.key', 'module.pos', 'Clé d\'activation de la caisse', 'Générez une clé et saisissez-la dans l\'application Caisse.', in_array('pos', $keys, true), 'backend', ['type' => 'screen', 'screen' => 'app_keys']);
            $add('pos.register', 'module.pos', 'Poste de caisse', 'Créez au moins un poste de caisse (fond de caisse, terminal).', $this->count($pdo, 'cash_register', 'is_active = TRUE') > 0, 'backend', ['type' => 'screen', 'screen' => 'cash_registers']);
            // Cheminement complet (07/11/2026) : ce qu'il faut pour ouvrir le magasin (liste de contrôle de docs/mise-en-production-caisse.md)
            $cashiers = $this->count($pdo, '"user"', "roles::text LIKE '%ROLE_USER_POS%' OR roles::text LIKE '%ROLE_POS_MANAGER%'");
            $add('pos.cashiers', 'module.pos', 'Au moins un caissier', $cashiers > 0 ? sprintf('%d compte(s) de caisse (caissier ou responsable).', $cashiers) : 'Créez un compte utilisateur avec le rôle caissier (ou responsable de caisse) : c\'est lui qui ouvre la session dans l\'application.', $cashiers > 0, 'backend', ['type' => 'screen', 'screen' => 'users']);
            $add('pos.receipt', 'module.pos', 'En-tête et pied du ticket', 'Raison sociale, adresse et mentions (politique de retour) imprimées sur chaque ticket, numéros de taxe affichés (fiche Entreprise, panneau Ticket).', $filled($e['pos_receipt_header'] ?? null), 'backend', ['type' => 'screen', 'screen' => 'entreprise'], false);
            $posLive = ($e['pos_stripe_mode'] ?? 'test') === StripeAccountResolver::LIVE;
            $posAccount = trim((string) ($stripeRow[$posLive ? 'live_account_id' : 'account_id'] ?? '')) !== '' || $this->tenants->isTenantInternal($code);
            $add('pos.terminal', 'module.pos', 'Paiements par carte au terminal (Stripe)', $posLive ? ($posAccount ? 'Caisse en mode réel avec un compte Stripe connecté : appairez le lecteur sur la fiche du poste.' : 'Caisse en mode réel sans compte Stripe connecté : inscrivez le compte (page Paiements de caisse) ou repassez en mode test.') : 'Mode test : les cartes sont simulées. Pour encaisser réellement au lecteur : inscription Stripe du magasin, appairage du lecteur, puis « Passer en réel » (page Paiements de caisse).', $posLive ? $posAccount : false, 'backend', ['type' => 'screen', 'screen' => 'pos_stripe'], $posLive);
            $add('pos.alerts', 'module.pos', 'Alertes de la caisse par courriel', 'Adresse qui reçoit les alertes (écart de caisse, terminal, hors ligne) : fiche Entreprise, panneau Surveillance.', $filled($e['pos_alerts_email'] ?? null), 'backend', ['type' => 'screen', 'screen' => 'entreprise'], false);
            $add('pos.retention', 'module.pos', 'Conservation des données de vente', 'Durée légale de conservation réglée selon le pays (Canada : 6 ans) et garde de modification (fiche Entreprise, panneau Conservation).', (int) ($e['data_retention_years'] ?? 0) > 0, 'backend', ['type' => 'screen', 'screen' => 'entreprise'], false);
            $currency = strtoupper(trim((string) ($e['currency'] ?? 'CAD'))) ?: 'CAD';
            $add('pos.denominations', 'module.pos', 'Coupures de la devise', sprintf('Billets et pièces en %s pour le comptage du fond de caisse et de la clôture (écran Type Cash) ; fournis d\'office pour le dollar canadien.', $currency), $this->count($pdo, 'type_cash', sprintf("active = TRUE AND UPPER(COALESCE(currency, 'CAD')) = '%s'", $currency)) > 0, 'backend', ['type' => 'screen', 'screen' => 'denominations'], false);
            if ($posLive) {
                $add('pos.reader', 'module.pos', 'Lecteur de carte appairé', 'Au moins un poste avec le terminal activé et un lecteur Stripe appairé (fiche du poste, code d\'appairage).', $this->count($pdo, 'cash_register', "is_active = TRUE AND terminal_enabled = TRUE AND COALESCE(stripe_reader_id, '') <> ''") > 0, 'backend', ['type' => 'screen', 'screen' => 'cash_registers'], false);
            }
            $add('pos.first_sale', 'module.pos', 'Une première vente d\'essai', 'Ouvrez une session, vendez un article, clôturez (rapport Z) : la chaîne complète est vérifiée.', $this->count($pdo, '"order"', 'order_source_id = 2') > 0, 'backend', ['type' => 'screen', 'screen' => 'pos'], false);
        }
        if ($effective['module.memoires']['enabled'] ?? false) {
            $add('memoires.ai_quotas', 'module.memoires', 'Quotas IA des livres', 'Transcriptions et améliorations par jour (page Quotas IA Mémoires Vivantes) : des valeurs par défaut s\'appliquent sans réglage.', $this->count($pdo, 'mv_ai_quota_setting') > 0, 'backend', ['type' => 'screen', 'screen' => 'mv_ai_quotas'], false);
            $add('memoires.book_type', 'module.memoires', 'Types de livre', 'Au moins un type de livre actif (fournis par le modèle, modifiables dans la console Mémoires Vivantes).', $this->count($pdo, 'mv_book_type', 'is_active = TRUE') > 0, 'editor', ['type' => 'editor', 'url' => $editorUrl]);
        }
        if ($effective['module.boussole']['enabled'] ?? false) {
            $add('boussole.questions', 'module.boussole', 'Questions du diagnostic', 'Au moins une question active (fournies par le modèle).', $this->count($pdo, 'esg_diag_question', 'is_active = TRUE') > 0, 'backend', ['type' => 'screen', 'screen' => 'esg_questions']);
        }
        if ($effective['app.mobile_shop']['enabled'] ?? false) {
            $add('mobile_shop.key', 'app.mobile_shop', 'Clé de l\'application e-commerce', 'Générez la clé d\'activation de l\'application mobile.', in_array('mobile', $keys, true), 'backend', ['type' => 'screen', 'screen' => 'app_keys']);
            $add('mobile_shop.short_code', 'app.mobile_shop', 'Code court de la boutique', 'Le code que vos clients tapent dans l\'application.', !empty($host['shortCode']), 'backend', ['type' => 'screen', 'screen' => 'short_code']);
            $add('mobile_shop.profile', 'app.mobile_shop', 'Apparence de l\'application', 'Logo, couleurs et nom affichés dans l\'application (profil « mobile », page Apparence de l\'app mobile) ; sans profil, la fiche Entreprise sert.', $this->count($pdo, 'app_profile', "app = 'mobile'") > 0, 'backend', ['type' => 'screen', 'screen' => 'app_profiles'], false);
        }
        if ($effective['app.management']['enabled'] ?? false) {
            $this->managementSteps($steps, $pdo, $e, $address, $keys, $host, $effective);
        }
        if ($effective['module.support']['enabled'] ?? false) { // module Assistance, lot 1 (12/11/2026) : appScreen comme le groupe de la gestion
            $support = function (string $key, string $title, string $description, string $status, bool $blocking, ?string $appScreen) use (&$steps) {
                $steps[] = ['key' => $key, 'group' => 'module.support', 'title' => $title, 'description' => $description, 'status' => $status, 'where' => 'backend',
                    'action' => ['type' => 'screen', 'screen' => 'mon_site'], 'blocking' => $blocking, 'appScreen' => $appScreen];
            };
            $agents = $this->count($pdo, '"user"', "roles::text LIKE '%ROLE_SUPPORT%' OR roles::text LIKE '%ROLE_ADMIN%'");
            $setting = $this->row($pdo, 'SELECT saved_at, outbound_mode FROM support_setting ORDER BY id LIMIT 1') ?? [];
            $support('support.agent', 'Un compte qui répond aux demandes', $agents > 0 ? sprintf('%d compte(s) administrateur ou Assistance répondent aux demandes.', $agents) : 'Un administrateur (ou un compte Assistance) répond aux demandes.', $agents > 0 ? 'done' : 'todo', true, null);
            $support('support.settings', 'Délai de réponse', ($setting['saved_at'] ?? null) !== null ? 'Délai de réponse réglé (heures ouvrées, jours ouvrés).' : 'Vérifiez le délai de réponse (48 h ouvrées par défaut, du lundi au vendredi) et enregistrez-le.', ($setting['saved_at'] ?? null) !== null ? 'done' : 'todo', true, 'assistance-reglages');
            $support('support.outbound', 'Envoi des réponses par courriel', ($setting['outbound_mode'] ?? 'log') === 'send' ? 'Les réponses partent par courriel au client.' : 'Les réponses restent dans la demande (mode journal) : passez à l\'envoi par courriel quand l\'équipe est prête.', ($setting['outbound_mode'] ?? 'log') === 'send' ? 'done' : 'todo', false, 'assistance-reglages');
            $support('support.inbound', 'Adresse de réception des courriels', 'Les courriels des clients arriveront dans les demandes (adresse fournie par la plateforme, à venir avec le lot 2).', 'optional', false, null);
        }
        if ($effective['app.living_memory']['enabled'] ?? false) {
            $add('living_memory.key', 'app.living_memory', 'Clé de l\'application Living Memory', 'Générez la clé d\'activation portée par l\'application pour votre site.', in_array('livingmemory', $keys, true), 'backend', ['type' => 'screen', 'screen' => 'app_keys']);
            $add('living_memory.profile', 'app.living_memory', 'Apparence de l\'application Living Memory', 'Logo, couleurs et textes de l\'application (profil « livingmemory »).', $this->count($pdo, 'app_profile', "app = 'livingmemory'") > 0, 'backend', ['type' => 'screen', 'screen' => 'app_profiles'], false);
        }

        // Assistant IA (cheminement complet, 07/11/2026). Accès réel (08/11/2026) : même règle que SiteAssistantService::tier, pour que la carte
        // « Assistant IA » dise le palier du site au lieu de « prêt » en toute circonstance
        $tier = $this->entitlements->isEnabled($code, 'ai.full') ? 'full' : ($this->entitlements->isEnabled($code, 'ai.help') ? 'help' : 'none'); // même en mode démo : l'IA suit les droits (IA bornée, 08/11/2026)
        $add('ai.access', 'ai', ['full' => 'Assistant IA : IA complète', 'help' => 'Assistant IA : aide IA', 'none' => 'Assistant IA : non activé'][$tier],
            ['full' => 'Le service « IA complète » est accordé : l\'assistant explique, propose des réglages et des opérations, toujours après votre confirmation.',
                'help' => 'Le service « Aide IA » est accordé : l\'assistant explique vos réglages, votre cheminement et vos commandes, sans rien modifier. « IA complète » s\'obtient par forfait ou sur demande.',
                'none' => 'Aucun service d\'assistant n\'est accordé au site : demandez ou essayez « Aide IA » ou « IA complète » dans Services et quotas, ou prenez un forfait.'][$tier],
            $tier !== 'none', 'backend', ['type' => 'screen', 'screen' => 'mon_site'], false);
        $creditsRaw = $this->one($pdo, 'SELECT monthly_credits FROM ai_credit_setting ORDER BY id LIMIT 1');
        $credits = $creditsRaw === null ? $this->platform->aiDefaultCredits() : (int) $creditsRaw; // défaut réglé par la plateforme (08/11/2026)
        $monthStart = (new \DateTimeImmutable('first day of this month 00:00:00', new \DateTimeZone(\App\Services\LandingAiService\LandingAiQuotaService::TIMEZONE)))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $usedCredits = (int) ($this->one($pdo, sprintf("SELECT COALESCE(SUM(credits), 0) FROM ai_usage WHERE created_at >= '%s' AND (status = 'success' OR (status = 'reserved' AND reserved_until >= NOW()))", $monthStart)) ?? 0);
        $remainingCredits = max(0, $credits - $usedCredits);
        $creditsAction = $shopUrl !== null ? ['type' => 'shop', 'feature' => 'ai.credits', 'url' => $shopUrl] : ['type' => 'screen', 'screen' => 'mon_site'];
        // Crédits réglés par la plateforme (07/11/2026) : l'étape ne mène plus au réglage mais à l'achat ou au changement de forfait
        $add('ai.credits', 'ai', 'Crédits IA du mois', $credits === 0 ? 'Aucun crédit IA ce mois-ci : l\'assistant et la rédaction par l\'IA refuseront. Achetez des crédits ou changez de forfait.' : ($remainingCredits === 0 ? sprintf('Crédits épuisés (%d utilisés sur %d) : l\'IA refusera jusqu\'au mois prochain. Achetez des crédits ou changez de forfait.', $usedCredits, $credits) : ($remainingCredits * 5 <= $credits ? sprintf('Il reste %d crédit(s) sur %d : pensez à acheter des crédits ou à changer de forfait avant la panne.', $remainingCredits, $credits) : sprintf('%d crédit(s) restant(s) sur %d ce mois-ci pour l\'assistant, les pages et les fiches produit.', $remainingCredits, $credits))), $credits > 0 && $remainingCredits * 5 > $credits, 'backend', $creditsAction, $credits === 0 || $remainingCredits === 0); // bloquant sans crédit (08/11/2026) : la carte passe « à faire »

        // Fonctionnalités non accordées : à obtenir (hors avancement)
        foreach (array_keys(FeatureCatalog::GOVERNED) as $feature) {
            if ($effective[$feature]['enabled'] ?? false) {
                continue;
            }
            $isPending = isset($pending[$feature]);
            $trial = ($policies[$feature]['trial_allowed'] ?? false) && !isset($trials[$feature]);
            $steps[] = [
                'key' => 'obtain.' . $feature, 'group' => $feature, 'title' => 'Obtenir : ' . FeatureCatalog::label($feature), 'description' => FeatureCatalog::DESCRIPTIONS[$feature] ?? '',
                'status' => $isPending ? 'pending' : 'optional', 'where' => 'platform', 'blocking' => false,
                // `trial` : POST /api/site/features/{clé}/request ouvre un essai gratuit immédiat ; `request` : même route, demande à la plateforme ; `shop` : boutique de forfaits
                'action' => $trial ? ['type' => 'trial', 'feature' => $feature, 'days' => (int) $policies[$feature]['trial_days'], 'shopUrl' => $shopUrl] : ($shopUrl !== null ? ['type' => 'shop', 'feature' => $feature, 'url' => $shopUrl] : ['type' => 'request', 'feature' => $feature]),
            ];
        }

        return ['steps' => $steps, 'links' => $this->links($domain, $effective, $this->landingTabs($pdo, (string) ($e['locale'] ?? 'fr')))];
    }

    /**
     * Onglets de la landing page, dans l'ordre, avec le slug que le site Next met dans l'adresse (`/landingpage/<slug>`) : titre dans la
     * langue du site, en ascii minuscule avec des tirets, rendu unique par un suffixe -2, -3… (même règle que l'assistant IA, `pageKeys`).
     *
     * @return list<array{title: string, slug: string}>
     */
    private function landingTabs(\PDO $pdo, string $locale): array
    {
        $raw = $this->one($pdo, 'SELECT configuration FROM landing_page_setting ORDER BY id LIMIT 1');
        $config = is_string($raw) ? json_decode($raw, true) : null;
        $tabs = [];
        $seen = [];
        foreach (is_array($config['tabs'] ?? null) ? $config['tabs'] : [] as $tab) {
            $title = $tab['title'] ?? null;
            if (is_array($title)) {
                $title = $title[$locale] ?? $title['fr'] ?? $title['en'] ?? (reset($title) ?: null);
            }
            $title = trim((string) $title);
            if ($title === '') {
                continue;
            }
            $ascii = class_exists(\Transliterator::class) ? transliterator_transliterate('Any-Latin; Latin-ASCII', $title) : iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
            $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $ascii)), '-') ?: 'onglet';
            $slug = $base;
            for ($n = 2; isset($seen[$slug]); $n++) {
                $slug = "$base-$n";
            }
            $seen[$slug] = true;
            $tabs[] = ['title' => $title, 'slug' => $slug];
        }

        return $tabs;
    }

    /**
     * Liens « Voir le résultat » (08/11/2026) : l'hôte est le domaine propre dès qu'il sert le site en HTTPS, sinon l'adresse de la plateforme
     * (un domaine encore en attente de DNS donnerait des liens morts). `pages` : la landing page d'abord (`site` = `/landingpage`, l'accueil ;
     * les autres onglets en `landing.<slug>` à `/landingpage/<slug>`), puis chaque module accordé qui a une page.
     *
     * @param array<string, mixed> $domain état du domaine propre (`CustomDomainService::status`)
     * @param array<string, array<string, mixed>> $effective droits effectifs du site
     * @param list<array{title: string, slug: string}> $tabs onglets de la landing page
     * @return array{host: string, platformHost: string, customDomain: ?string, customState: string, pages: list<array{key: string, label: string, url: string}>}
     */
    private function links(array $domain, array $effective, array $tabs): array
    {
        $host = $domain['state'] === 'live' && is_string($domain['domain']) ? $domain['domain'] : (string) $domain['platformHost'];
        $pages = [];
        foreach ($tabs as $i => $tab) {
            // Premier onglet : /landingpage sans slug, Next y sert l'accueil lui-même (décision du propriétaire, 08/11/2026)
            $pages[] = $i === 0
                ? ['key' => 'site', 'label' => 'Site : ' . $tab['title'], 'url' => 'https://' . $host . '/landingpage']
                : ['key' => 'landing.' . $tab['slug'], 'label' => $tab['title'], 'url' => 'https://' . $host . '/landingpage/' . $tab['slug']];
        }
        if ($pages === []) {
            $pages[] = ['key' => 'site', 'label' => 'Accueil du site', 'url' => 'https://' . $host . '/'];
        }
        foreach (self::PUBLIC_PATHS as $feature => $path) {
            if ($effective[$feature]['enabled'] ?? false) {
                $pages[] = ['key' => $feature, 'label' => FeatureCatalog::label($feature), 'url' => 'https://' . $host . $path];
            }
        }

        return ['host' => $host, 'platformHost' => (string) $domain['platformHost'], 'customDomain' => $domain['domain'], 'customState' => (string) $domain['state'], 'pages' => $pages];
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
