<?php

namespace App\Services\Platform;

use App\Entity\Entreprise;
use App\Entity\GooglePlacesConfig;
use App\Entity\ReviewSetting;
use App\Services\EmailConfigurationService\EmailConfigurationService;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\EmailConfigurationService\TenantEmailDelivery;
use App\Services\OrderService\TaxEngine;
use App\Services\PosStripeService\PosStripeAccountGatewayInterface;
use App\Services\StripeService\StripeAccountResolver;
use App\Services\TenantEntityManagerProvider;

/**
 * Cheminements de configuration des parties complexes d'un site (lot 5, 07/11/2026) : paiements en ligne (Stripe), taxes, courriels,
 * avis clients. Lecture seule : chaque cheminement est une liste d'étapes (faite, à faire, facultative, en attente de la plateforme)
 * avec l'écran où agir, sur le modèle de la page Livraison (`ShippingSetupService`). Le courriel d'essai est la seule action.
 */
class SiteConfigurationPathsService
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly StripeAccountResolver $stripe,
        private readonly PosStripeAccountGatewayInterface $stripeAccounts,
        private readonly TaxEngine $taxes,
        private readonly EmailConfigurationService $emailConfig,
        private readonly EmailSenderService $sender,
    ) {
    }

    /** @return array<string, array{key: string, title: string, intro: string, level: string, levelLabel: string, steps: list<array<string, mixed>>}> */
    public function overview(): array
    {
        return ['stripe' => $this->stripePath(), 'taxes' => $this->taxesPath(), 'email' => $this->emailPath(), 'reviews' => $this->reviewsPath()];
    }

    /** Un seul cheminement (pour l'assistant et les tests) */
    public function path(string $key): ?array
    {
        return $this->overview()[$key] ?? null;
    }

    // ---------------------------------------------------------------- Stripe

    private function stripePath(): array
    {
        $steps = [];
        $mode = $this->stripe->mode(StripeAccountResolver::CHANNEL_SHOP);
        $modeLabel = $mode === StripeAccountResolver::LIVE ? 'réel' : 'test';
        $internal = $this->stripe->isInternal();
        $keys = $this->stripe->keysConfigured($mode);
        $this->step($steps, 'stripe.platform_keys', 'Clés Stripe de la plateforme', $keys ? sprintf('La plateforme encaisse en mode %s : vos paiements passent par son compte Stripe et sont reversés sur votre compte connecté.', $modeLabel) : 'La plateforme n\'a pas encore configuré ses clés Stripe : aucun paiement en ligne possible pour l\'instant.', $keys ? 'done' : 'pending', null);
        $row = $this->row('SELECT account_id, is_active, live_account_id, live_is_active, online_fee_bp FROM stripe_config ORDER BY id LIMIT 1');
        $accountId = trim((string) ($mode === StripeAccountResolver::LIVE ? ($row['live_account_id'] ?? '') : ($row['account_id'] ?? '')));
        if ($internal) {
            $this->step($steps, 'stripe.account', 'Compte Stripe du site', 'Site interne : le compte de la plateforme encaisse directement, rien à connecter.', 'done', 'stripe_config');
        } else {
            $this->step($steps, 'stripe.account', sprintf('Compte Stripe connecté (%s)', $modeLabel), $accountId !== '' ? sprintf('Compte %s connecté. Les fonds vous sont versés par Stripe selon votre calendrier de virements.', StripeAccountResolver::mask($accountId)) : 'Cliquez « Connecter » sur l\'écran Paiements en ligne (Stripe) : Stripe vous guide (identité, compte bancaire). Renseignez d\'abord l\'adresse de l\'entreprise : elle fixe le pays du compte.', $accountId !== '' ? 'done' : 'todo', 'stripe_config');
            if ($accountId !== '' && $keys) {
                $status = $this->accountStatus($mode, $accountId);
                if ($status === null) {
                    $this->step($steps, 'stripe.account_ready', 'Compte vérifié par Stripe', 'Vérification impossible pour le moment (Stripe injoignable) : réessayez plus tard.', 'pending', 'stripe_config');
                } elseif ($status['chargesEnabled'] && $status['payoutsEnabled']) {
                    $this->step($steps, 'stripe.account_ready', 'Compte vérifié par Stripe', 'Encaissements et virements activés.', 'done', 'stripe_config');
                } else {
                    $due = array_slice(array_unique(array_merge($status['pastDue'], $status['currentlyDue'])), 0, 6);
                    $this->step($steps, 'stripe.account_ready', 'Compte vérifié par Stripe', sprintf('Stripe attend encore des informations%s%s. Reprenez l\'inscription depuis l\'écran Stripe (« Connecter ») ou dans votre tableau de bord Stripe.', $due ? ' : ' . implode(', ', $due) : '', $status['disabledReason'] ? ' (' . $status['disabledReason'] . ')' : ''), 'todo', 'stripe_config');
                }
            }
        }
        $webhook = $this->env('STRIPE_WEBHOOK_SECRET') || $this->env('STRIPE_CONNECT_WEBHOOK_SECRET') || $this->env('STRIPE_DIRECT_WEBHOOK_SECRET');
        $this->step($steps, 'stripe.webhook', 'Notifications Stripe (webhook)', $webhook ? 'La plateforme reçoit les notifications Stripe (paiements confirmés, remboursements, abonnements, litiges) : rien à régler côté site.' : 'La plateforme n\'a pas encore branché les notifications Stripe : les abonnements et litiges ne seraient pas suivis automatiquement.', $webhook ? 'done' : 'pending', null);
        $paid = (int) $this->one("SELECT COUNT(*) FROM payments WHERE stripe_payment_id IS NOT NULL AND stripe_status = 'succeeded' AND amount > 0");
        $this->step($steps, 'stripe.first_payment', 'Un premier paiement reçu', $paid > 0 ? sprintf('%d paiement(s) par carte encaissé(s) : la chaîne fonctionne.', $paid) : ($mode === StripeAccountResolver::LIVE ? 'Passez une petite commande réelle sur votre site puis remboursez-la depuis la fiche de la commande : vous vérifiez le paiement, le reçu et le remboursement d\'un coup.' : 'Mode test : passez une commande sur votre site avec la carte de test 4242 4242 4242 4242 (n\'importe quelle date future et CVC). Aucun argent ne circule.'), $paid > 0 ? 'done' : ($accountId !== '' || $internal ? 'todo' : 'optional'), null);
        $fee = (int) ($row['online_fee_bp'] ?? 100);
        $this->step($steps, 'stripe.fee', 'Commission de la plateforme', sprintf('%s %% sur chaque paiement en ligne, prélevée automatiquement par Stripe au moment du paiement (en plus des frais Stripe).', rtrim(rtrim(number_format($fee / 100, 2, ',', ''), '0'), ',')), 'done', 'stripe_config');
        $ready = $keys && ($internal || $accountId !== '');
        $level = !$ready ? 'off' : ($paid > 0 ? 'proven' : 'connected');

        return $this->build('stripe', 'Paiements en ligne (Stripe)', sprintf('Vos clients paient par carte sur le site et dans l\'application ; Stripe encaisse pour la plateforme et vous reverse les fonds. Mode actuel : %s.', $modeLabel), $level, ['off' => 'Pas encore de paiement en ligne', 'connected' => 'Compte connecté', 'proven' => 'Paiements vérifiés'][$level], $steps);
    }

    private function accountStatus(string $mode, string $accountId): ?array
    {
        try {
            return $this->stripeAccounts->retrieveAccount($mode, $accountId);
        } catch (\Throwable) {
            return null;
        }
    }

    // ---------------------------------------------------------------- taxes

    private function taxesPath(): array
    {
        $steps = [];
        $raw = $this->row('SELECT country, state, city, zip FROM address_entreprise ORDER BY id LIMIT 1');
        $address = TaxEngine::normalizeAddress($raw ? ['country' => $raw['country'], 'province' => $raw['state'], 'city' => $raw['city'], 'postalCode' => $raw['zip']] : null);
        $this->step($steps, 'tax.address', 'Adresse de l\'entreprise', $address ? sprintf('Pays %s%s : les taxes s\'appliquent selon le lieu de livraison, cette adresse sert aux retraits en magasin et aux services.', $address['country'], $address['province'] ? ', région ' . $address['province'] : '') : 'Renseignez le pays et la région de l\'entreprise (écran Adresse de l\'entreprise) : sans eux, impossible de savoir quelles taxes s\'appliquent.', $address ? 'done' : 'todo', 'address_entreprise');
        $inclusive = $this->taxes->pricesIncludeTax();
        $this->step($steps, 'tax.prices', 'Prix affichés', $inclusive ? 'Prix taxes comprises (TTC) : la taxe est extraite du prix affiché (usage en France et en Europe).' : 'Prix hors taxes : la taxe s\'ajoute au panier (usage au Canada et aux États-Unis). Modifiable sur la fiche Entreprise.', 'done', 'entreprise');
        $provider = $this->taxes->provider();
        $this->step($steps, 'tax.provider', 'Mode de calcul', $provider === TaxEngine::PROVIDER_STRIPE ? 'Stripe Tax calcule les taxes selon le lieu de livraison et vos immatriculations déclarées chez Stripe (frais Stripe Tax par transaction).' : 'Table des taxes du site : vous saisissez vos taux par pays et région (TPS, TVQ, TVH, TVA…). Gratuit et suffisant pour un ou quelques pays. Choix dans « Mon site : pilotage », rubrique Commerce.', 'done', 'mon_site');
        if ($provider === TaxEngine::PROVIDER_STRIPE) {
            $this->step($steps, 'tax.stripe_prices', 'Prix hors taxes requis', $inclusive ? 'Stripe Tax ne fonctionne pas avec des prix taxes comprises : passez en prix hors taxes (fiche Entreprise) ou revenez à la table des taxes.' : 'Prix hors taxes : compatible avec Stripe Tax.', $inclusive ? 'todo' : 'done', 'entreprise');
            $this->step($steps, 'tax.stripe_registrations', 'Immatriculations déclarées chez Stripe', 'Dans votre tableau de bord Stripe, rubrique Tax, déclarez les pays et provinces où vous êtes immatriculé : Stripe ne taxe que là. Vérifiez ensuite par une commande d\'essai.', 'optional', 'stripe_config');
        } else {
            $count = (int) $this->one('SELECT COUNT(*) FROM tax');
            $applicable = $address ? $this->taxes->applicableTaxes($address) : [];
            $names = array_map(fn ($t) => sprintf('%s %s %%', $t->getName(), rtrim(rtrim(number_format((float) $t->getRate(), 3, ',', ''), '0'), ',')), $applicable);
            $this->step($steps, 'tax.table', 'Taxes de la table', $count === 0 ? 'Aucune taxe : ajoutez vos taux (Canada : TPS 5 % fédérale puis la taxe de votre province, par exemple TVQ 9,975 % au Québec ; France : TVA 20 %). Pays vide = tous les pays ; région « Toutes » ou codes séparés par des virgules.' : ($address && $applicable === [] ? sprintf('%d taxe(s) saisie(s) mais aucune ne s\'applique à votre propre adresse (%s) : vérifiez pays et région de chaque ligne.', $count, $address['country'] . ($address['province'] ? '-' . $address['province'] : '')) : sprintf('%d taxe(s) saisie(s)%s.', $count, $names ? ', à votre adresse : ' . implode(', ', $names) : '')), $count === 0 || ($address && $applicable === []) ? 'todo' : 'done', 'taxes');
            $categorized = (int) $this->one("SELECT COUNT(*) FROM tax WHERE category IS NOT NULL AND category <> ''");
            $this->step($steps, 'tax.categories', 'Taux réduits par catégorie de produit', $categorized > 0 ? sprintf('%d taxe(s) limitée(s) à une catégorie fiscale (taux réduit, intermédiaire…).', $categorized) : 'Une taxe sans catégorie frappe tous les produits non exonérés. Pour des taux réduits (France : 10 %, 5,5 %), créez une taxe par catégorie et classez les produits concernés.', $categorized > 0 ? 'done' : 'optional', 'taxes');
        }
        $todo = array_filter($steps, fn ($s) => $s['status'] === 'todo');
        $level = $todo === [] ? 'ready' : 'todo';

        return $this->build('taxes', 'Taxes', 'Comment la taxe de vente est calculée sur vos commandes et vos reçus. La règle : la taxe du lieu de livraison (ou de votre adresse pour un retrait ou un service).', $level, ['ready' => 'Taxes prêtes', 'todo' => 'À compléter'][$level], $steps);
    }

    // ---------------------------------------------------------------- courriels

    private function emailPath(): array
    {
        $steps = [];
        $config = $this->emailConfig->findDefault();
        $from = trim((string) $config?->getFromEmail());
        $name = trim((string) $config?->getFromName());
        $this->step($steps, 'email.sender', 'Expéditeur des courriels', $from !== '' ? sprintf('Vos courriels partent de « %s%s ».', $name !== '' ? $name . ' » <' : '', $from . ($name !== '' ? '>' : '')) : 'Indiquez l\'adresse et le nom d\'expéditeur (écran Expéditeur des courriels) : confirmations de commande, reçus, codes de connexion, avis d\'expédition.', $from !== '' ? 'done' : 'todo', 'email_configuration');
        $own = TenantEmailDelivery::isComplete($config);
        $this->step($steps, 'email.smtp', 'Serveur d\'envoi (SMTP) du site', $own ? sprintf('Vos courriels partent par votre propre serveur (%s).', (string) $config->getSmtpHost()) : 'Sans serveur propre, la plateforme envoie pour vous : rien d\'obligatoire. Pour partir de votre boîte (meilleure délivrabilité, réponses reçues chez vous), saisissez serveur, port, utilisateur et mot de passe SMTP de votre hébergeur de courriel.', $own ? 'done' : 'optional', 'email_configuration');
        $domain = strtolower((string) substr(strrchr($from, '@') ?: '', 1));
        if ($from !== '' && $domain !== '' && !TenantEmailDelivery::isReserved($from)) {
            $dns = $this->dns($domain);
            if ($dns === null) {
                $this->step($steps, 'email.dns', 'Autorisations du domaine (SPF, DMARC)', 'Vérification DNS impossible pour le moment : réessayez plus tard.', 'pending', null);
            } else {
                $missing = array_keys(array_filter($dns, fn ($ok) => !$ok));
                $this->step($steps, 'email.dns', 'Autorisations du domaine (SPF, DMARC)', $missing === [] ? sprintf('Le domaine %s publie SPF et DMARC : vos courriels ont toutes les chances d\'arriver en boîte de réception.', $domain) : sprintf('Le domaine %s n\'a pas de %s. Chez votre registraire (zone DNS), ajoutez%s. %s Sans cela, vos courriels risquent le dossier indésirable.', $domain, implode(' ni de ', array_map('strtoupper', $missing)), in_array('spf', $missing, true) ? ' un enregistrement TXT « v=spf1 … » autorisant votre serveur d\'envoi' . (!$own ? ' (demandez à la plateforme son inclusion « include: »)' : '') : '', in_array('dmarc', $missing, true) ? 'Ajoutez aussi un TXT « _dmarc » (« v=DMARC1; p=none; rua=mailto:vous@' . $domain . ' »).' : '') . ($own ? ' Activez DKIM chez votre hébergeur de courriel.' : ''), $missing === [] ? 'done' : 'todo', null);
            }
        } elseif ($from !== '') {
            $this->step($steps, 'email.dns', 'Autorisations du domaine (SPF, DMARC)', 'Adresse d\'un domaine réservé ou d\'essai : aucune vérification.', 'optional', null);
        }
        $logo = trim((string) ($config?->getLogo() ?? '')) !== '' || trim((string) $this->one('SELECT logo FROM entreprise ORDER BY id LIMIT 1')) !== '';
        $this->step($steps, 'email.logo', 'Logo et signature', $logo ? 'Votre logo apparaît en tête des courriels.' : 'Ajoutez un logo (fiche Entreprise ou expéditeur) et une signature : vos courriels inspirent confiance.', $logo ? 'done' : 'optional', 'email_configuration');
        $this->step($steps, 'email.test', 'Courriel d\'essai', 'Envoyez-vous un courriel d\'essai (bouton ci-dessous) et vérifiez qu\'il arrive, dans la boîte de réception et non en indésirable, avec le bon expéditeur.', $from !== '' ? 'todo' : 'optional', null, ['type' => 'email_test']);
        $todo = array_filter($steps, fn ($s) => $s['status'] === 'todo' && $s['key'] !== 'email.test');
        $level = $from === '' ? 'off' : ($todo === [] ? 'ready' : 'todo');

        return $this->build('email', 'Courriels', 'Les courriels que votre site envoie à vos clients (confirmations, reçus, codes de connexion, avis d\'expédition) et à vous (alertes). Sans réglage, la plateforme envoie pour vous.', $level, ['off' => 'Aucun expéditeur', 'todo' => 'À compléter', 'ready' => 'Courriels prêts'][$level], $steps);
    }

    /** @return array{spf: bool, dmarc: bool}|null null si la résolution a échoué */
    private function dns(string $domain): ?array
    {
        try {
            $txt = @dns_get_record($domain, DNS_TXT);
            $dmarc = @dns_get_record('_dmarc.' . $domain, DNS_TXT);
            if ($txt === false && $dmarc === false) {
                return null;
            }
            $has = fn ($records, string $prefix) => (bool) array_filter((array) $records, fn ($r) => str_starts_with(strtolower(trim((string) ($r['txt'] ?? ''))), $prefix));

            return ['spf' => $has($txt, 'v=spf1'), 'dmarc' => $has($dmarc, 'v=dmarc1')];
        } catch (\Throwable) {
            return null;
        }
    }

    /** Envoie un courriel d'essai à l'administrateur connecté ; renvoie le canal utilisé ou null si rien n'est parti */
    public function sendTestEmail(string $to, string $domain): ?string
    {
        $config = $this->emailConfig->findDefault();
        $via = TenantEmailDelivery::isComplete($config) ? 'site' : 'platform';
        $sent = $this->sender->sendTemplatedEmail($to, 'Courriel d\'essai de votre site', 'emails/test_email.html.twig', ['via' => $via, 'from' => (string) $config?->getFromEmail(), 'sentAt' => new \DateTimeImmutable()], 'fr', $domain);

        return $sent ? $via : null;
    }

    // ---------------------------------------------------------------- avis clients

    private function reviewsPath(): array
    {
        $steps = [];
        $em = $this->emProvider->getEntityManager();
        $setting = $em->getRepository(ReviewSetting::class)->findOneBy([]);
        $enabled = $setting?->isEnabled() ?? true;
        $moderation = $setting?->getModeration() ?? ReviewSetting::MODERATION_MANUAL;
        $this->step($steps, 'reviews.settings', 'Réglages des avis', $enabled ? sprintf('Avis activés, %s, modération %s.%s', ($setting?->isVerifiedOnly() ?? true) ? 'acheteurs vérifiés seulement' : 'tout client connecté', $moderation === ReviewSetting::MODERATION_MANUAL ? 'avant publication' : 'automatique', $setting === null ? ' (valeurs par défaut : créez la ligne pour les changer)' : '') : 'Avis désactivés : vos fiches produits n\'affichent ni note ni avis.', 'done', 'review_settings');
        $policy = array_filter((array) ($setting?->getPolicy() ?? []), fn ($t) => trim((string) $t) !== '');
        $this->step($steps, 'reviews.policy', 'Politique des avis affichée', $policy !== [] ? sprintf('Politique rédigée (%s).', implode(', ', array_keys($policy))) : 'Expliquez sous les avis comment ils sont contrôlés (achat vérifié, publication des avis négatifs) : c\'est attendu par la loi en France et dans l\'UE, et rassurant partout.', $policy !== [] ? 'done' : ($enabled ? 'todo' : 'optional'), 'review_settings');
        $pending = (int) $this->one("SELECT COUNT(*) FROM reviews_product WHERE status = 'pending'");
        $approved = (int) $this->one("SELECT COUNT(*) FROM reviews_product WHERE status = 'approved'");
        $this->step($steps, 'reviews.moderation', 'Avis en attente', $pending > 0 ? sprintf('%d avis attend(ent) votre lecture : publiez ou refusez (motif), jamais selon la note.', $pending) : sprintf('Aucun avis en attente ; %d publié(s).', $approved), $pending > 0 ? 'todo' : 'done', 'reviews');
        $google = $this->googlePlacesKey();
        $this->step($steps, 'reviews.google_places', 'Adresses Google Places (facultatif)', $google ? 'Clé Google Places en place : saisie d\'adresse assistée au paiement et sur les fiches.' : 'Avec une clé Google Places (console Google Cloud, API Places), vos clients saisissent leur adresse en quelques lettres : moins d\'erreurs de livraison.', $google ? 'done' : 'optional', 'google_places');
        $level = !$enabled ? 'off' : ($pending > 0 || $policy === [] ? 'todo' : 'ready');

        return $this->build('reviews', 'Avis clients', 'Les avis que vos clients laissent sur vos produits : qui peut écrire, comment ils sont modérés, ce que vous affichez. Les avis négatifs se publient aussi : la loi l\'exige et la confiance en dépend.', $level, ['off' => 'Avis désactivés', 'todo' => 'À traiter', 'ready' => 'Avis prêts'][$level], $steps);
    }

    private function googlePlacesKey(): bool
    {
        try {
            $cfg = $this->emProvider->getEntityManager()->getRepository(GooglePlacesConfig::class)->findOneBy([]);

            return $cfg !== null && (trim((string) $cfg->getGoogleApiKeyTest()) !== '' || trim((string) $cfg->getGoogleApiKeyProd()) !== '');
        } catch (\Throwable) {
            return false;
        }
    }

    // ---------------------------------------------------------------- outils

    private function build(string $key, string $title, string $intro, string $level, string $levelLabel, array $steps): array
    {
        return ['key' => $key, 'title' => $title, 'intro' => $intro, 'level' => $level, 'levelLabel' => $levelLabel, 'steps' => $steps, 'todo' => count(array_filter($steps, fn ($s) => $s['status'] === 'todo'))];
    }

    private function step(array &$steps, string $key, string $title, string $description, string $status, ?string $screen, ?array $action = null): void
    {
        $steps[] = ['key' => $key, 'title' => $title, 'description' => $description, 'status' => $status, 'screen' => $screen, 'action' => $action];
    }

    private function env(string $name): bool
    {
        return trim((string) ($_ENV[$name] ?? $_SERVER[$name] ?? getenv($name) ?: '')) !== '';
    }

    private function one(string $sql): mixed
    {
        try {
            return $this->emProvider->getEntityManager()->getConnection()->fetchOne($sql);
        } catch (\Throwable) {
            return null;
        }
    }

    private function row(string $sql): ?array
    {
        try {
            $row = $this->emProvider->getEntityManager()->getConnection()->fetchAssociative($sql);

            return $row === false ? null : $row;
        } catch (\Throwable) {
            return null;
        }
    }
}
