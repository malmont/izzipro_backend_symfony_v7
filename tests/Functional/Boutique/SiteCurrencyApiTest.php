<?php

namespace App\Tests\Functional\Boutique;

use App\Controller\Admin\EntrepriseCrudController;
use App\Controller\Admin\TypeCashCrudController;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\BoutiqueSettingsService\SiteCurrencyService;
use App\Services\CaisseService\DenominationService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * F18 partie 1 (01/11/2026) : devise du site et arrondi des espèces connus dès le démarrage de la caisse (GET /api/pos/settings,
 * GET /api/app-profile/pos), coupures par défaut d'une devise (fiche Entreprise, commande, bouton EasyAdmin ; jamais à la lecture),
 * devise verrouillée dès qu'un montant est enregistré. Aucun calcul de taxe ni de total ne change.
 */
class SiteCurrencyApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-devise@example.invalid', false, false);
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', pos_cash_rounding_step = NULL");
        $this->db->executeStatement("DELETE FROM type_cash WHERE currency IS NULL OR UPPER(currency) <> 'CAD'"); // coupures créées par ce test
    }

    public function testSettingsAndPosProfileCarryTheCurrencyAndTheCashRoundingStep(): void
    {
        $this->loginAs(['ROLE_USER_POS']);
        $s = $this->json($this->request('GET', '/api/pos/settings'));
        $this->assertSame(['CAD', 5], [$s['currency'], $s['cashRoundingStep']], 'site non réglé : CAD, 5 ¢ (comportement d\'avant)');
        $this->assertSame('cad', $s['terminal']['currency'], 'la devise du terminal (minuscules, Stripe) ne change pas');
        $this->assertSame('CAD', $this->profile('pos')['currency'], 'profil public de la caisse');
        foreach (['mobile', 'admin', 'memoires'] as $app) {
            $this->assertArrayNotHasKey('currency', $this->profile($app), "les autres applications ne changent pas : $app");
        }

        $this->db->executeStatement("UPDATE entreprise SET currency = 'EUR'");
        $s = $this->json($this->request('GET', '/api/pos/settings'));
        $this->assertSame(['EUR', 1], [$s['currency'], $s['cashRoundingStep']], 'EUR sans réglage : aucun arrondi = 1 (jamais 0, comme pack.site.cashRoundingStep)');
        $this->assertSame('EUR', $this->profile('pos')['currency']);

        $this->db->executeStatement('UPDATE entreprise SET pos_cash_rounding_step = 10');
        $this->assertSame(10, $this->json($this->request('GET', '/api/pos/settings'))['cashRoundingStep'], 'réglage du site');
    }

    public function testDenominationsAreReadOnlyAndDefaultsAreCreatedOnceByCommandOrButton(): void
    {
        $this->loginAs(['ROLE_USER_POS']);
        $journalBefore = (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = 'DENOMINATIONS_CREATED'"); // journal immuable : comptage relatif
        $this->db->executeStatement("UPDATE entreprise SET currency = 'EUR'");
        $list = $this->request('GET', '/api/caisse/denominations');
        $this->assertSame([200, '[]'], [$list->getStatusCode(), $list->getContent()], 'aucune coupure dans la devise : liste vide, rien n\'est écrit');
        $this->assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM type_cash WHERE currency = 'EUR'"));

        $created = static::getContainer()->get(DenominationService::class)->createDefaults('eur', 'command');
        $this->assertSame(14, $created);
        $list = $this->json($this->request('GET', '/api/caisse/denominations'));
        $this->assertSame(['200 €', '100 €', '50 €', '20 €', '10 €', '5 €', '2 €', '1 €', '50 ¢', '20 ¢', '10 ¢', '5 ¢', '2 ¢', '1 ¢'], array_column($list, 'label'));
        $this->assertSame([20000, 'bill', true], [$list[0]['valueCents'], $list[0]['kind'], $list[0]['active']]);
        $this->assertSame([1, 'coin', true], [$list[13]['valueCents'], $list[13]['kind'], $list[13]['active']]);
        $this->assertCount(14, array_unique(array_column($list, 'id')), 'vrais identifiants, utilisables dans cashDetails');
        $this->assertSame(0, static::getContainer()->get(DenominationService::class)->createDefaults('EUR', 'command'), 'idempotent');
        $this->assertSame(14, (int) $this->db->fetchOne("SELECT COUNT(*) FROM type_cash WHERE currency = 'EUR'"));
        $journal = $this->db->fetchAllAssociative("SELECT payload FROM pos_audit_log WHERE type = 'DENOMINATIONS_CREATED' ORDER BY id");
        $this->assertCount($journalBefore + 1, $journal, 'une ligne au journal, pas de doublon à la seconde demande');
        $payload = json_decode(end($journal)['payload'], true)['data'];
        $this->assertSame(['command', 'EUR', 14], [$payload['source'], $payload['currency'], $payload['count']]);
        $this->assertSame(11, (int) $this->db->fetchOne("SELECT COUNT(*) FROM type_cash WHERE currency = 'CAD'"), 'les coupures CAD d\'origine ne bougent pas');

        // Commande console : rien à faire en EUR, création en USD (50 ¢ et 1 $ inactives), un site inconnu échoue
        $tester = new CommandTester((new Application(static::$kernel))->find('app:pos:denominations-create'));
        $this->assertSame(0, $tester->execute(['--tenant' => MV_TEST_TENANT_CODE]));
        $this->assertStringContainsString('mvtest : EUR, coupures déjà présentes', $tester->getDisplay());
        $this->db->executeStatement("UPDATE entreprise SET currency = 'USD'");
        $this->assertSame(0, $tester->execute(['--tenant' => MV_TEST_TENANT_CODE, '--dry-run' => true]));
        $this->assertStringContainsString('13 coupures seraient créées', $tester->getDisplay());
        $this->assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM type_cash WHERE currency = 'USD'"), 'dry-run : rien d\'écrit');
        $this->assertSame(0, $tester->execute(['--tenant' => MV_TEST_TENANT_CODE]));
        $this->assertStringContainsString('mvtest : USD, 13 coupures créées', $tester->getDisplay());
        $usd = $this->json($this->request('GET', '/api/caisse/denominations'));
        $this->assertSame(['100 $', '50 $', '20 $', '10 $', '5 $', '2 $', '1 $', '1 $', '50 ¢', '25 ¢', '10 ¢', '5 ¢', '1 ¢'], array_column($usd, 'label'));
        $this->assertSame([['coin', false], ['coin', false], ['coin', true]], [[$usd[7]['kind'], $usd[7]['active']], [$usd[8]['kind'], $usd[8]['active']], [$usd[9]['kind'], $usd[9]['active']]], '1 $ et 50 ¢ en pièces inactives, 25 ¢ active');
        $this->assertSame(1, $tester->execute(['--tenant' => 'inconnu']));

        // Bouton EasyAdmin : idempotent lui aussi
        $this->loginAdmin();
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => 'createDefaults', 'crudControllerFqcn' => TypeCashCrudController::class]), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 303]);
        $this->assertSame(13, (int) $this->db->fetchOne("SELECT COUNT(*) FROM type_cash WHERE currency = 'USD'"), 'rien de plus : la devise a déjà ses coupures');
        $this->db->executeStatement("UPDATE entreprise SET currency = 'EUR'");
        $this->db->executeStatement("DELETE FROM type_cash WHERE currency = 'EUR'");
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => 'createDefaults', 'crudControllerFqcn' => TypeCashCrudController::class]), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(14, (int) $this->db->fetchOne("SELECT COUNT(*) FROM type_cash WHERE currency = 'EUR'"), 'bouton : liste par défaut créée');
        $this->assertSame('easyadmin', json_decode($this->db->fetchOne("SELECT payload FROM pos_audit_log WHERE type = 'DENOMINATIONS_CREATED' ORDER BY id DESC LIMIT 1"), true)['data']['source']);
    }

    public function testTheCompanyFormChangesTheCurrencyOnlyWhileNothingIsRecordedAndCreatesTheDenominations(): void
    {
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement('DELETE FROM cash_details WHERE transaction_caisse_id IN (SELECT id FROM transaction_caisse)');
        $service = static::getContainer()->get(SiteCurrencyService::class);
        $locked = $service->isLocked();
        $this->loginAdmin();
        $id = (int) $this->db->fetchOne('SELECT MIN(id) FROM entreprise');

        if (!$locked) {
            // Site sans montant : la devise se change dans la fiche et les coupures par défaut suivent
            $crawler = $this->edit($id);
            $select = $crawler->filter('select[name="Entreprise[currency]"]');
            $this->assertCount(1, $select, 'champ Devise du site présent');
            $this->assertNull($select->attr('disabled'), 'modifiable');
            $this->assertSame(['CAD', 'EUR', 'USD'], $select->filter('option')->each(fn ($o) => $o->attr('value')));
            $form = $crawler->filter('form[name="Entreprise"]')->form();
            $values = $form->getPhpValues();
            $values['Entreprise']['currency'] = 'EUR';
            $this->client->request($form->getMethod(), $form->getUri(), $values, $form->getPhpFiles());
            $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 303], (string) $this->client->getResponse()->getContent());
            $this->assertSame('EUR', $this->db->fetchOne('SELECT currency FROM entreprise WHERE id = ?', [$id]));
            $this->assertSame(14, (int) $this->db->fetchOne("SELECT COUNT(*) FROM type_cash WHERE currency = 'EUR'"), 'coupures EUR créées avec la devise');
            $this->assertSame(['entreprise', 'CAD', 'EUR'], array_values(array_intersect_key(json_decode($this->db->fetchOne("SELECT payload FROM pos_audit_log WHERE type = 'CURRENCY_CHANGED' ORDER BY id DESC LIMIT 1"), true)['data'], ['source' => 1, 'from' => 1, 'to' => 1])));
            $this->assertSame('EUR', $this->profile('pos')['currency']);
            // Devise inconnue refusée, la fiche garde EUR
            $values['Entreprise']['currency'] = 'GBP';
            $this->client->request($form->getMethod(), $form->getUri(), $values, $form->getPhpFiles());
            $this->assertSame('EUR', $this->db->fetchOne('SELECT currency FROM entreprise WHERE id = ?', [$id]));
            $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD'");
        }

        // Site avec un montant enregistré (une session de caisse suffit) : lecture seule, et une requête forgée ne change rien
        $register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $session = null;
        if (!$locked) {
            $this->db->executeStatement('INSERT INTO caisse (id, amount_total, created_at, is_open, fon_de_caisse, cash_register_id, closed_at) VALUES ((SELECT COALESCE(MAX(id), 0) + 1 FROM caisse), 0, NOW(), false, 0, ?, NOW())', [$register]); // id sans valeur par défaut
            $session = (int) $this->db->fetchOne('SELECT MAX(id) FROM caisse');
        }
        $this->assertTrue($service->isLocked());
        $this->assertStringStartsWith('Devise verrouillée : ce site a déjà', (string) $service->lockMessage());
        $crawler = $this->edit($id);
        $select = $crawler->filter('select[name="Entreprise[currency]"]');
        $this->assertSame('disabled', $select->attr('disabled'), 'lecture seule');
        $this->assertStringContainsString('Devise verrouillée', $this->client->getResponse()->getContent());
        $before = $this->db->fetchOne('SELECT currency FROM entreprise WHERE id = ?', [$id]);
        $form = $crawler->filter('form[name="Entreprise"]')->form();
        $values = $form->getPhpValues();
        $values['Entreprise']['currency'] = $before === 'USD' ? 'EUR' : 'USD'; // champ désactivé : ignoré par le formulaire, et refusé par le garde-fou de updateEntity s'il passait
        $this->client->request($form->getMethod(), $form->getUri(), $values, $form->getPhpFiles());
        $this->assertSame($before, $this->db->fetchOne('SELECT currency FROM entreprise WHERE id = ?', [$id]), 'la devise ne change pas');
        $this->assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM type_cash WHERE currency = ?", [$values['Entreprise']['currency']]), 'aucune coupure créée');
        try {
            $service->assertChangeAllowed('CAD', 'USD');
            $this->fail('verrouillée');
        } catch (\DomainException $e) {
            $this->assertStringStartsWith('Devise verrouillée', $e->getMessage());
        }
        if ($session !== null) {
            $this->db->executeStatement('DELETE FROM caisse WHERE id = ?', [$session]);
        }
    }

    private function edit(int $id): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => 'edit', 'crudControllerFqcn' => EntrepriseCrudController::class, 'entityId' => $id]), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        return $crawler;
    }

    private function profile(string $app): array
    {
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/api/app-profile/$app", [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    private function json(Response $response, int $expected = 200): array
    {
        $this->assertSame($expected, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent(), true);
    }

    private function request(string $method, string $path, ?array $body = null): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null,
        ]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function loginAdmin(): void
    {
        $user = $this->user(['ROLE_ADMIN']);
        $this->client->loginUser($user, 'main');
    }

    private function loginAs(array $roles): void
    {
        $user = $this->user($roles);
        $this->token = null;
        $login = $this->request('POST', '/api/login', ['username' => $user->getEmail(), 'password' => self::PASSWORD, 'platform' => 'pos']);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];
    }

    private function user(array $roles): User
    {
        $email = 'devise-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Devise')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $user;
    }
}
