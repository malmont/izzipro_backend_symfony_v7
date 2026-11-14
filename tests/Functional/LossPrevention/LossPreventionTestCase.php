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
    /** Marque des noms des comptes ; null = au hasard à chaque test (pour vérifier qu'aucun nom ne sort là où il ne doit pas) */
    protected const FIXED_MARK = null;
    protected KernelBrowser $client;
    protected TenantEntityManagerProvider $provider;
    protected \Doctrine\DBAL\Connection $db;
    protected ?string $posToken = null;
    protected string $adminToken = '';
    protected int $adminId = 0;
    protected int $register;
    protected int $cap;
    /** Marque propre à chaque test, portée par le prénom et le nom des comptes : aucun texte sans nom ne doit la contenir */
    protected string $mark;
    /** @var list<array<string, mixed>> adresse du site avant le test (pays, province), remise à la fin : la langue du site en dépend */
    private array $addresses = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $this->provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-pertes@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->addresses = $this->db->fetchAllAssociative('SELECT id, country, state FROM address_entreprise ORDER BY id');
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
        $this->db->executeStatement('UPDATE loss_setting SET staff_notice_confirmed_at = NULL, staff_notice_confirmed_by = NULL, person_patterns_enabled = NULL, analysis_floor_sequence = NULL');
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
        static::getContainer()->get(PlatformModeService::class)->setMode('demo', 'test');
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
     * Une analyse montée à la main, avec les règles données à la place des vraies : pour jouer une règle en échec.
     *
     * @param array{drawer?: \App\LossPrevention\Services\Rule\DrawerNoSaleRule, series?: \App\LossPrevention\Services\Rule\CashShortageSeriesRule} $rules
     */
    protected function analysisWith(array $rules): \App\LossPrevention\Services\LossAnalysisService
    {
        $provider = $this->tenant();
        $c = static::getContainer();

        return new \App\LossPrevention\Services\LossAnalysisService($provider, $c->get(\App\LossPrevention\Services\LossSettingsService::class),
            $c->get(\App\LossPrevention\Services\LossRuleService::class), $c->get(\App\LossPrevention\Services\LossSite::class),
            new \App\LossPrevention\Services\LossFindingWriter($provider), new \Psr\Log\NullLogger(), new \App\LossPrevention\Services\Rule\CashDiscrepancyRule(),
            $rules['series'] ?? new \App\LossPrevention\Services\Rule\CashShortageSeriesRule(), $rules['drawer'] ?? new \App\LossPrevention\Services\Rule\DrawerNoSaleRule(),
            new \App\LossPrevention\Services\Rule\VoidAfterSaleRule());
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

    /** Ferme la session ouverte avec un écart donné (compté − attendu, cents) @return array<string, mixed> rapport de clôture */
    protected function closeWith(int $delta, ?string $reason = null): array
    {
        $x = $this->json($this->pos('GET', "/api/caisse/report/x?cashRegisterId=$this->register"));

        return $this->json($this->pos('POST', '/api/caisse/close', array_filter(['cashRegisterId' => $this->register, 'countedCash' => $x['expectedCash'] + $delta, 'keptFund' => 0,
            'discrepancyReason' => $reason], fn ($v) => $v !== null)))['report'];
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
