<?php

namespace App\Tests\Functional\LossPrevention;

use App\Entity\Entreprise;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\CaisseService\StoreClock;
use App\Services\Platform\PlatformModeService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Socle des tests de la prévention des pertes : site de test préparé pour la caisse, comptes, appels de la caisse et de l'administrateur,
 * journée de caisse (ouvrir, vendre en espèces, fermer avec un écart). Le droit du module est accordé au site de test (sans lui, rien n'est
 * analysé, même en mode « demo » de la plateforme). Preuves et journal du module sont inaltérables : rien n'est effacé ;
 * chaque test épuise d'abord l'analyse, puis ne regarde que ce que ses propres sessions de caisse ont fait naître.
 */
abstract class LossPreventionTestCase extends WebTestCase
{
    protected const PASSWORD = 'Mot-de-passe-de-test-1!';
    protected const BASE = '/api/gestion/pertes';
    /** Colonnes de la fiche du site que ces tests règlent pour jouer une journée de caisse : gardées avant, remises après */
    private const SITE_SETTINGS = ['locale', 'currency', 'prices_include_tax', 'pos_discrepancy_threshold', 'pos_cash_rounding_step', 'pos_approval_refund', 'pos_approval_withdrawal',
        'pos_approval_fund_withdrawal', 'pos_approval_drawer_open', 'pos_approval_close_without_count', 'pos_terminal_header_required', 'pos_pay_cash', 'pos_return_without_receipt',
        'pos_return_cash_for_card_sale', 'pos_return_max_days', 'pos_approval_return', 'pos_discount_enabled', 'pos_discount_ticket_enabled', 'pos_discount_reason_required',
        'pos_discount_cashier_max_percent_bp', 'pos_discount_cashier_max_amount', 'pos_price_override_enabled', 'pos_price_override_max_amount', 'pos_price_override_reason_required',
        'pos_offline_enabled', 'pos_offline_max_hours', 'pos_offline_max_queued_sales', 'pos_offline_max_queued_amount', 'pos_offline_price_tolerance', 'pos_offline_pack_validity_hours'];
    /** Marque des noms des comptes ; null = au hasard à chaque test (pour vérifier qu'aucun nom ne sort là où il ne doit pas) */
    protected const FIXED_MARK = null;
    protected KernelBrowser $client;
    protected TenantEntityManagerProvider $provider;
    protected \Doctrine\DBAL\Connection $db;
    protected ?string $posToken = null;
    protected string $adminToken = '';
    protected int $adminId = 0;
    /** Jeton de la tablette de caisse (en-tête X-Pos-Terminal), quand un test en active une */
    protected ?string $terminal = null;
    protected int $register;
    protected int $cap;
    /** Marque propre à chaque test, portée par le prénom et le nom des comptes : aucun texte sans nom ne doit la contenir */
    protected string $mark;
    /** @var list<array<string, mixed>> adresse du site avant le test (pays, province), remise à la fin : la langue du site en dépend */
    private array $addresses = [];
    /** @var array<string, mixed> réglages de caisse du site de test avant le test (ceux que ces tests changent), remis à la fin */
    private array $siteSettings = [];
    /** @var array{management: bool, loss: bool} droits de la gestion et du module sur le site de test avant le test, remis à la fin */
    private array $moduleFlags = ['management' => false, 'loss' => false];
    /** Mode de la plateforme avant le test (« demo » ou « live »), remis à la fin */
    private string $platformMode = 'demo';
    /** @var list<int> clés d'activation créées par activateTerminal() (l'identifiant de la tablette est celui de sa clé), révoquées à la fin */
    private array $createdTerminals = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $this->provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-pertes@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->addresses = $this->db->fetchAllAssociative('SELECT id, country, state FROM address_entreprise ORDER BY id');
        $this->siteSettings = $this->db->fetchAssociative('SELECT ' . implode(', ', self::SITE_SETTINGS) . ' FROM entreprise ORDER BY id LIMIT 1') ?: [];
        $this->platformMode = static::getContainer()->get(PlatformModeService::class)->mode();
        $this->createdTerminals = [];
        $this->moduleFlags = array_map('boolval', $this->db->fetchAssociative('SELECT is_management_app_active AS management, is_loss_prevention_active AS loss FROM entreprise ORDER BY id LIMIT 1') ?: ['management' => false, 'loss' => false]);
        $this->place('CA', 'ON');
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', prices_include_tax = false, pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL, pos_approval_refund = NULL, pos_approval_withdrawal = NULL, pos_approval_fund_withdrawal = NULL, pos_approval_drawer_open = false, pos_approval_close_without_count = false, pos_terminal_header_required = false, pos_pay_cash = true, is_loss_prevention_active = true");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement("UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), terminal_enabled = false, stripe_reader_id = NULL, receipt_prefix = 'C1-'");
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->cap = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $this->db->executeStatement("UPDATE product SET price = 2900, special_price = NULL, is_pos = true, tax_category = NULL WHERE code = 'DEMO-CASQUETTE'");
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = 5000, price = NULL WHERE id = ?', [$this->cap]);
        // Réglages du module remis à leurs valeurs de départ (tables de réglages : modifiables ; preuves et journal : jamais touchés)
        $this->db->executeStatement('DELETE FROM loss_rule');
        $this->db->executeStatement('INSERT INTO loss_setting (retention_findings_months, retention_confirmed_months) VALUES (24, 60) ON CONFLICT DO NOTHING');
        $this->db->executeStatement('UPDATE loss_setting SET staff_notice_confirmed_at = NULL, staff_notice_confirmed_by = NULL, person_patterns_enabled = NULL, analysis_floor_sequence = NULL, last_digest_day = NULL, last_digest_finding_id = NULL');
        $this->provider->getEntityManager()->clear();
        $this->mark = static::FIXED_MARK ?? 'Zq' . bin2hex(random_bytes(4));
        $admin = $this->user(['ROLE_ADMIN'], 'Gerant');
        $this->adminId = (int) $admin->getId();
        $this->adminToken = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($admin);
    }

    protected function tearDown(): void
    {
        // Le site de test retrouve son adresse : la langue du site en est déduite, et les tests des autres modules (courriels, notifications)
        // la lisent après ceux-ci dans la suite complète
        foreach ($this->addresses as $row) {
            $this->db->executeStatement('UPDATE address_entreprise SET country = ?, state = ? WHERE id = ?', [$row['country'], $row['state'], $row['id']]);
        }
        if ($this->siteSettings !== []) { // réglages de caisse du site : comme avant le test
            $this->db->executeStatement('UPDATE entreprise SET ' . implode(', ', array_map(fn (string $c) => "$c = :$c", array_keys($this->siteSettings))), $this->siteSettings,
                array_map(fn ($v) => is_bool($v) ? \Doctrine\DBAL\ParameterType::BOOLEAN : ($v === null ? \Doctrine\DBAL\ParameterType::NULL : \Doctrine\DBAL\ParameterType::STRING), $this->siteSettings));
        }
        $this->flags($this->moduleFlags['management'], $this->moduleFlags['loss']); // écriture Doctrine : le cache des indicateurs de modules suit
        static::getContainer()->get(PlatformModeService::class)->setMode($this->platformMode, 'test'); // le mode trouvé, pas « demo » en dur
        // Clés d'activation (donc tablettes) créées par activateTerminal() : révoquées, elles s'accumulaient d'un test à l'autre
        foreach ($this->createdTerminals as $keyId) {
            $this->call('DELETE', '/api/app-keys/' . $keyId, null, $this->adminToken);
        }
        parent::tearDown();
        // PHPUnit garde chaque instance de test jusqu'à la fin de la suite : une connexion tenue par une propriété resterait ouverte, et la
        // suite complète épuise alors les connexions du serveur de base de données (« too many clients »)
        if (isset($this->db)) {
            $this->db->close();
        }
        unset($this->db, $this->provider, $this->client);
    }

    // ----- helpers -----

    /** Épuise l'analyse : tout ce que le journal de caisse porte déjà est lu avant le scénario du test */
    protected function drain(): void
    {
        for ($i = 0; $i < 50; ++$i) {
            $run = $this->json($this->admin('POST', self::BASE . '/analyse'));
            if (!$run['pending']) {
                return;
            }
        }
        $this->fail('analyse jamais épuisée');
    }

    /** Le fournisseur du noyau en cours, replacé sur le site de test (un service appelé hors d'une requête n'a pas de site) */
    protected function tenant(): TenantEntityManagerProvider
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider;
    }

    /**
     * Une analyse montée à la main, avec des règles données à la place des vraies (la règle remplacée est celle dont la classe est la mère de
     * la règle donnée) : pour jouer une règle en échec.
     *
     * @param list<\App\LossPrevention\Services\Rule\LossRuleInterface> $replacements
     */
    protected function analysisWith(array $replacements): \App\LossPrevention\Services\LossAnalysisService
    {
        $provider = $this->tenant();
        $c = static::getContainer();
        $rules = [];
        foreach (['CashDiscrepancyRule', 'CashShortageSeriesRule', 'DrawerNoSaleRule', 'VoidAfterSaleRule', 'RefundPatternRule', 'RefundSeriesRule', 'DiscountPatternRule', 'DiscountSeriesRule',
            'PriceOverrideRule', 'ApprovalPatternRule'] as $name) {
            $class = '\\App\\LossPrevention\\Services\\Rule\\' . $name;
            $rules[$class] = new $class();
        }
        foreach ($replacements as $rule) {
            $rules['\\' . get_parent_class($rule)] = $rule;
        }

        return new \App\LossPrevention\Services\LossAnalysisService($provider, $c->get(\App\LossPrevention\Services\LossSettingsService::class),
            $c->get(\App\LossPrevention\Services\LossRuleService::class), $c->get(\App\LossPrevention\Services\LossSite::class),
            new \App\LossPrevention\Services\LossFindingWriter($provider), new \Psr\Log\NullLogger(), array_values($rules));
    }

    /** Une règle « tiroir » qui échoue sur une erreur SQL (la transaction serait perdue sans point de reprise) */
    protected static function failingDrawerRule(): \App\LossPrevention\Services\Rule\DrawerNoSaleRule
    {
        return new class extends \App\LossPrevention\Services\Rule\DrawerNoSaleRule {
            public function handle(array $entry, \App\LossPrevention\Services\LossAnalysisContext $ctx): void
            {
                $ctx->conn->executeStatement('SELECT colonne_qui_n_existe_pas FROM pos_audit_log');
            }
        };
    }

    /** Active une tablette de caisse (clé d'activation « pos ») et garde son jeton pour les appels suivants ; rend son identifiant */
    protected function activateTerminal(string $label): int
    {
        $key = $this->json($this->call('POST', '/api/app-keys', ['app' => 'pos', 'label' => $label, 'sendEmail' => false], $this->adminToken), 201)['key'];
        $terminal = $this->json($this->call('POST', '/api/app/activate', ['key' => $key, 'app' => 'pos'], null))['terminal'];
        $this->terminal = (string) $terminal['token'];
        $this->createdTerminals[] = (int) $terminal['id'];

        return (int) $terminal['id'];
    }

    protected function place(string $country, string $state): void
    {
        $this->db->executeStatement('UPDATE address_entreprise SET country = ?, state = ?', [$country, $state]);
        $this->provider->getEntityManager()->clear();
    }

    /** Écriture Doctrine (vide le cache des indicateurs de modules) */
    protected function flags(bool $management, bool $lossPrevention): void
    {
        $em = $this->provider->getEntityManager();
        $em->clear();
        $em->getRepository(Entreprise::class)->findOneBy([], ['id' => 'ASC'])->setIsManagementAppActive($management)->setIsLossPreventionActive($lossPrevention);
        $em->flush();
    }

    protected function openSession(): int
    {
        return (int) $this->json($this->pos('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]), 201)['caisse_id'];
    }

    /** @return array<string, mixed> réponse de la vente (ticket) */
    protected function sellCash(): array
    {
        $quote = $this->json($this->pos('POST', '/api/cart/quote', ['items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'cashRegisterId' => $this->register]));

        return $this->json($this->pos('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->cap, 'quantity' => 1]],
            'paymentMethods' => [['type' => 2, 'amount' => $quote['cashTotal'] / 100]], 'cashRegisterId' => $this->register]), 201);
    }

    /**
     * Une vente au comptoir d'une casquette, payée en entier par un moyen (2 espèces par défaut, 1 carte), avec un prix manuel ou une remise de
     * ticket facultatifs ; le total vient du devis de caisse.
     *
     * @param array<string, mixed> $lineExtras clés ajoutées à la ligne (`priceOverride`)
     * @param array<string, mixed>|null $ticketDiscount
     * @return array<string, mixed> réponse de la vente
     */
    protected function sell(array $lineExtras = [], ?array $ticketDiscount = null, int $method = 2): array
    {
        $body = ['items' => [['productVariantId' => $this->cap, 'quantity' => 1] + $lineExtras], 'cashRegisterId' => $this->register] + ($ticketDiscount !== null ? ['ticketDiscount' => $ticketDiscount] : []);
        $quote = $this->json($this->pos('POST', '/api/pos/quote', $body));

        return $this->json($this->pos('POST', '/api/order/create-multi-payment', $body + ['paymentMethods' => [['type' => $method, 'amount' => ($method === 2 ? $quote['cashTotal'] : $quote['total']) / 100]]]), 201);
    }

    /** Ferme la session ouverte avec un écart donné (compté − attendu, cents) @return array<string, mixed> rapport de clôture */
    protected function closeWith(int $delta, ?string $reason = null): array
    {
        $x = $this->json($this->pos('GET', "/api/caisse/report/x?cashRegisterId=$this->register"));

        return $this->json($this->pos('POST', '/api/caisse/close', array_filter(['cashRegisterId' => $this->register, 'countedCash' => $x['expectedCash'] + $delta, 'keptFund' => 0,
            'discrepancyReason' => $reason], fn ($v) => $v !== null)))['report'];
    }

    /**
     * Les adresses (http, https) d'un courriel, sans doublon : un courriel du module n'en porte qu'UNE, le lien vers l'écran de l'espace
     * d'administration (lot LIEN), sur le domaine du site.
     *
     * @return list<string>
     */
    protected static function addressesOf(string $html): array
    {
        preg_match_all('#https?://[^\s"\'<>]+#i', html_entity_decode($html, ENT_QUOTES), $m);

        return array_values(array_unique($m[0]));
    }

    /** Le lien attendu vers un écran de l'espace d'administration du site de test (le service commun du lot LIEN) */
    protected function adminLink(string $screen, array $params = []): ?string
    {
        $this->tenant();

        return static::getContainer()->get(\App\Services\Site\AdminSpaceUrl::class)->to($screen, $params);
    }

    /**
     * Deux plafonds du jumelage par code (lot L3-a2) sont gardés d'un lancement à l'autre sous une clé qui ne change pas : les essais
     * par SITE (un site d'essai n'a qu'un nom) et les demandes nouvelles de toute la plateforme. Ils repartent de zéro avant un
     * test qui jumelle.
     */
    protected static function resetPairingSiteLimit(): void
    {
        foreach ([MV_TEST_TENANT_CODE, MV_TEST_TENANT2_CODE] as $site) {
            self::pairingLimiter('pairAdminSite', '15 minutes', $site)->reset();
        }
        self::pairingLimiter('pairNew', '1 hour', 'plateforme')->reset();
    }

    /** Le compteur d'un plafond du jumelage, tel que `LossBoxLimits` le tient */
    protected static function pairingLimiter(string $name, string $interval, string $key): \Symfony\Component\RateLimiter\LimiterInterface
    {
        return (new \Symfony\Component\RateLimiter\RateLimiterFactory(['id' => 'loss_box_' . $name, 'policy' => 'sliding_window',
            'limit' => \App\LossPrevention\Services\LossBoxLimits::LIMITS[$name], 'interval' => $interval],
            new \Symfony\Component\RateLimiter\Storage\CacheStorage(static::getContainer()->get('cache.rate_limiter'))))->create($key);
    }

    protected function today(): string
    {
        return static::getContainer()->get(StoreClock::class)->day(new \DateTimeImmutable());
    }

    protected function json(Response $response, int $expected = 200): array
    {
        $this->assertSame($expected, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true);
    }

    protected function user(array $roles, string $firstname): User
    {
        $email = 'pertes-' . bin2hex(random_bytes(4)) . '@example.invalid';
        $container = static::getContainer();
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname($firstname . $this->mark)->setLastname('Nom' . $this->mark)->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $this->provider->getEntityManager()->persist($user);
        $this->provider->getEntityManager()->flush();

        return $user;
    }

    protected function loginPos(array $roles): User
    {
        $user = $this->user($roles, 'Caisse');
        $login = $this->call('POST', '/api/login', ['username' => $user->getEmail(), 'password' => self::PASSWORD, 'platform' => 'pos'], null);
        $this->assertSame(200, $login->getStatusCode(), (string) $login->getContent());
        $this->posToken = json_decode((string) $login->getContent(), true)['token'];

        return $user;
    }

    protected function pos(string $method, string $path, ?array $body = null): Response
    {
        return $this->call($method, $path, $body, $this->posToken);
    }

    /** Appel de l'administrateur ; en français sauf si le chemin demande une langue (la langue du site de test est l'anglais) */
    protected function admin(string $method, string $path, ?array $body = null): Response
    {
        if (str_starts_with($path, self::BASE) && !str_contains($path, 'locale=')) {
            $path .= (str_contains($path, '?') ? '&' : '?') . 'locale=fr';
        }

        return $this->call($method, $path, $body, $this->adminToken);
    }

    protected function call(string $method, string $path, ?array $body, ?string $token): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $token ? 'Bearer ' . $token : null,
            'HTTP_X_POS_TERMINAL' => $this->terminal,
            'HTTP_IDEMPOTENCY_KEY' => $method === 'POST' && (str_contains($path, 'create-multi-payment') || str_contains($path, '/cancel/')) ? self::uuid() : null,
        ]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    protected static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
