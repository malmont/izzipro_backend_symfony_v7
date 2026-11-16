<?php

namespace App\Tests\Functional\Platform;

use App\Entity\User;
use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\TenantDatabaseConnector;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot MOD-1 (16/11/2026) : LE DROIT d'un côté (achat, essai, super administrateur), L'INTERRUPTEUR du commerçant de l'autre. Le
 * commerçant allume et éteint un module auquel il a droit ; éteindre ne touche jamais au droit ; rallumer est immédiat. « Essayer » en
 * libre-service ouvre l'essai seul. La fiche du site reçoit « droit ET allumé », ce que lisent le garde-fou des modules et les menus.
 */
class ModuleSwitchTest extends WebTestCase
{
    private KernelBrowser $client;
    private \PDO $master;
    private string $tag;
    private int $lastEntitlement = 0;
    private int $lastKey = 0;
    /** @var array<string, mixed> */
    private array $sheet = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $this->tag = bin2hex(random_bytes(3));
        $this->lastEntitlement = (int) $this->master->query('SELECT COALESCE(MAX(id), 0) FROM tenant_entitlements')->fetchColumn();
        $this->lastKey = (int) $this->master->query('SELECT COALESCE(MAX(id), 0) FROM tenant_app_keys')->fetchColumn();
        $this->sheet = $this->site()->query('SELECT id, ' . implode(', ', FeatureCatalog::GOVERNED) . ' FROM entreprise ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC) ?: [];
        $this->assertNotSame([], $this->sheet);
    }

    protected function tearDown(): void
    {
        $this->master->prepare('DELETE FROM tenant_entitlement_events WHERE entitlement_id > ?')->execute([$this->lastEntitlement]);
        $this->master->prepare('DELETE FROM tenant_entitlements WHERE id > ?')->execute([$this->lastEntitlement]);
        $this->master->prepare('DELETE FROM tenant_app_keys WHERE id > ?')->execute([$this->lastKey]);
        $this->master->prepare('DELETE FROM tenant_feature_switches WHERE tenant_code = ?')->execute([MV_TEST_TENANT_CODE]);
        $this->master->exec("DELETE FROM platform_feature_policies WHERE updated_by = 'essai-mod1'");
        $set = implode(', ', array_map(static fn (string $c): string => "$c = :$c", FeatureCatalog::GOVERNED));
        $stmt = $this->site()->prepare("UPDATE entreprise SET $set WHERE id = :id");
        foreach ($this->sheet as $column => $value) {
            $stmt->bindValue(":$column", $column === 'id' ? (int) $value : in_array($value, [true, 't', 1, '1'], true), $column === 'id' ? \PDO::PARAM_INT : \PDO::PARAM_BOOL);
        }
        $stmt->execute();
        $this->site()->exec("DELETE FROM \"user\" WHERE email LIKE 'mod1-%@example.invalid'");
        parent::tearDown();
    }

    public function testTheMerchantSwitchesAHeldModuleOffAndOnWithoutTouchingTheRight(): void
    {
        $admin = $this->token('admin', ['ROLE_ADMIN']);
        // SANS DROIT : ni allumer ni éteindre
        $this->assertSame([409, 'NO_ENTITLEMENT'], $this->refusal('POST', '/api/site/features/module.support/enable', $admin));
        $this->assertSame([409, 'NO_ENTITLEMENT'], $this->refusal('POST', '/api/site/features/module.support/disable', $admin));
        $this->assertSame([422, 'VALIDATION_FAILED'], $this->refusal('POST', '/api/site/features/cle.inconnue/disable', $admin));
        // un client n'y touche pas
        $this->assertSame(403, $this->call('POST', '/api/site/features/module.support/disable', $this->token('client', ['ROLE_USER_INTERNET']))->getStatusCode());

        // UN DROIT accordé par la plateforme : le module marche
        $right = static::getContainer()->get(EntitlementService::class)->grant(MV_TEST_TENANT_CODE, 'module.support', 'manual', null, 'essai-mod1', null, null, null, false);
        $this->assertTrue($this->column('module.support'));

        // ÉTEINDRE : la fiche reçoit « éteint », le droit ne change pas
        $response = $this->call('POST', '/api/site/features/module.support/disable', $admin);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame(['key' => 'module.support', 'on' => false], json_decode((string) $response->getContent(), true));
        $this->assertFalse($this->column('module.support'), 'éteint : la fiche du site le dit, le garde-fou et les menus suivent');
        $this->assertSame(['active', null], $this->right((int) $right['id']), 'le droit n\'a pas bougé : ni statut ni fin');
        $feature = $this->feature('module.support', $admin);
        $this->assertSame([false, 'off', (int) $right['id']], [$feature['value'], $feature['switch'], $feature['entitlement']['id']], 'l\'état allumé / éteint, distinct du droit');
        $this->assertSame(1, $this->events((int) $right['id'], 'switched_off'));

        // un autre droit change ailleurs (projection de la fiche) : le module reste éteint
        static::getContainer()->get(EntitlementService::class)->project(MV_TEST_TENANT_CODE);
        $this->assertFalse($this->column('module.support'), 'la projection respecte l\'interrupteur');

        // RALLUMER : immédiat
        $response = $this->call('POST', '/api/site/features/module.support/enable', $admin);
        $this->assertSame(['key' => 'module.support', 'on' => true], json_decode((string) $response->getContent(), true));
        $this->assertTrue($this->column('module.support'));
        $this->assertSame('on', $this->feature('module.support', $admin)['switch']);
        $this->assertSame(1, $this->events((int) $right['id'], 'switched_on'));

        // le droit RETIRÉ : le module ne marche plus, et ne se rallume pas par l'interrupteur
        static::getContainer()->get(EntitlementService::class)->cancel((int) $right['id'], 'essai-mod1');
        $this->assertFalse($this->column('module.support'));
        $this->assertSame([409, 'NO_ENTITLEMENT'], $this->refusal('POST', '/api/site/features/module.support/enable', $admin));
    }

    public function testTryingAModuleOpensTheTrialOnlyOnceAndNeverFallsBackOnARequest(): void
    {
        $admin = $this->token('essai', ['ROLE_ADMIN']);
        // ce qu'il peut faire d'un module qu'il n'a pas
        $available = $this->feature('module.boussole', $admin)['available'];
        $this->assertSame([['days' => 30], true], [$available['trial'], $available['requestable']]);
        $this->assertSame(FeatureCatalog::DESCRIPTIONS['module.boussole'] ?? null, $available['description']);

        $response = $this->call('POST', '/api/site/features/module.boussole/trial', $admin);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame(['trial', 'trial', 'module.boussole'], [$body['kind'], $body['entitlement']['source'], $body['entitlement']['feature_key']]);
        $this->assertTrue($this->column('module.boussole'));
        $this->assertSame([null, false], [$this->feature('module.boussole', $admin)['available']['trial'], $this->feature('module.boussole', $admin)['available']['requestable']]);
        $this->assertSame([409, 'ALREADY_ENABLED'], $this->refusal('POST', '/api/site/features/module.boussole/trial', $admin));

        // un essai par fonctionnalité et par site
        static::getContainer()->get(EntitlementService::class)->cancel((int) $body['entitlement']['id'], 'essai-mod1');
        $this->assertSame([409, 'TRIAL_USED'], $this->refusal('POST', '/api/site/features/module.boussole/trial', $admin));
        $this->assertNull($this->feature('module.boussole', $admin)['available']['trial']);

        // aucun essai prévu : refusé, jamais une demande à la place
        $this->master->prepare("INSERT INTO platform_feature_policies (feature_key, trial_allowed, trial_days, free_allowed, grace_days, updated_by) VALUES ('module.memoires', false, 30, true, 7, 'essai-mod1') ON CONFLICT (feature_key) DO NOTHING")->execute();
        $requests = (int) $this->master->query("SELECT COUNT(*) FROM tenant_feature_requests WHERE tenant_code = '" . MV_TEST_TENANT_CODE . "'")->fetchColumn();
        $this->assertSame([409, 'NO_TRIAL'], $this->refusal('POST', '/api/site/features/module.memoires/trial', $admin));
        $this->assertSame($requests, (int) $this->master->query("SELECT COUNT(*) FROM tenant_feature_requests WHERE tenant_code = '" . MV_TEST_TENANT_CODE . "'")->fetchColumn(), 'aucune demande créée');
    }

    /** Point 3 révisé par le chef : AUCUNE clé n'est créée automatiquement ; le courriel d'octroi d'une application dit où la créer */
    public function testAnAppOnTrialGetsNoAutomaticKeyButTheGrantEmailSaysWhereToCreateIt(): void
    {
        $count = fn (): int => (int) $this->master->query("SELECT COUNT(*) FROM tenant_app_keys WHERE tenant_code = '" . MV_TEST_TENANT_CODE . "' AND id > " . $this->lastKey)->fetchColumn();
        $admin = $this->token('cle', ['ROLE_ADMIN']);
        $response = $this->call('POST', '/api/site/features/app.living_memory/trial', $admin);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame(0, $count(), 'aucune clé créée sans le commerçant');
        $mails = array_filter(self::getMailerMessages(), static fn ($m): bool => str_contains((string) $m->getHtmlBody(), 'Clés des applications'));
        $this->assertNotSame([], $mails, 'le courriel d\'octroi mène à « Clés des applications »');
        foreach (self::getMailerMessages() as $mail) {
            $this->assertDoesNotMatchRegularExpression('/IZ-[A-Z]+-[A-Z0-9]{4}-/', (string) $mail->getHtmlBody(), 'aucune clé dans un courriel de suivi');
        }
    }

    /** Un droit NEUF rallume un module éteint ; un droit SUSPENDU ne se contourne pas par un essai */
    public function testANewRightSwitchesTheModuleBackOnAndASuspendedRightIsNotBypassedByATrial(): void
    {
        $admin = $this->token('neuf', ['ROLE_ADMIN']);
        $rights = static::getContainer()->get(EntitlementService::class);
        $first = $rights->grant(MV_TEST_TENANT_CODE, 'module.support', 'manual', null, 'essai-mod1', null, null, null, false);
        $this->call('POST', '/api/site/features/module.support/disable', $admin);
        $this->assertFalse($this->column('module.support'));
        // un REMPLACEMENT (droit vivant avant lui) garde le choix du commerçant
        $rights->grant(MV_TEST_TENANT_CODE, 'module.support', 'manual', null, 'essai-mod1', null, null, null, false);
        $this->assertFalse($this->column('module.support'), 'renouvellement : toujours éteint');
        // après une fin, un droit NEUF rallume
        foreach ($rights->listForTenant(MV_TEST_TENANT_CODE) as $row) {
            if ($row['feature_key'] === 'module.support' && in_array($row['status'], ['active', 'grace'], true)) {
                $rights->cancel((int) $row['id'], 'essai-mod1');
            }
        }
        $rights->grant(MV_TEST_TENANT_CODE, 'module.support', 'manual', null, 'essai-mod1', null, null, null, false);
        $this->assertTrue($this->column('module.support'), 'droit neuf : allumé');
        $this->assertSame('on', $this->feature('module.support', $admin)['switch']);
        $this->assertGreaterThan(0, (int) $first['id']);

        // un droit SUSPENDU (impayé, agence suspendue) : ni essai, ni essai proposé
        $suspended = $rights->grant(MV_TEST_TENANT_CODE, 'module.boussole', 'manual', null, 'essai-mod1', null, null, null, false);
        $rights->suspend((int) $suspended['id'], 'essai-mod1', null, false);
        $this->assertNull($this->feature('module.boussole', $admin)['available']['trial']);
        $this->assertSame([409, 'ENTITLEMENT_SUSPENDED'], $this->refusal('POST', '/api/site/features/module.boussole/trial', $admin));
    }

    /** @return array<string, mixed> */
    private function feature(string $key, string $token): array
    {
        $response = $this->call('GET', '/api/site/features', $token);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return array_column(json_decode((string) $response->getContent(), true)['features'], null, 'key')[$key];
    }

    /** @return array{0: int, 1: ?string} */
    private function refusal(string $method, string $path, string $token): array
    {
        $response = $this->call($method, $path, $token);

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)['code'] ?? null];
    }

    private function call(string $method, string $path, string $token): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token], $method === 'GET' ? null : '{}');

        return $this->client->getResponse();
    }

    private function column(string $key): bool
    {
        return in_array($this->site()->query('SELECT ' . FeatureCatalog::GOVERNED[$key] . ' FROM entreprise ORDER BY id LIMIT 1')->fetchColumn(), [true, 't', 1, '1'], true);
    }

    /** @return array{0: string, 1: ?string} statut et fin d'un droit */
    private function right(int $id): array
    {
        $stmt = $this->master->prepare('SELECT status, ends_at FROM tenant_entitlements WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [(string) $row['status'], $row['ends_at']];
    }

    private function events(int $id, string $event): int
    {
        $stmt = $this->master->prepare('SELECT COUNT(*) FROM tenant_entitlement_events WHERE entitlement_id = ? AND event = ?');
        $stmt->execute([$id, $event]);

        return (int) $stmt->fetchColumn();
    }

    private function token(string $name, array $roles): string
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $provider->getEntityManager();
        $email = "mod1-$name-{$this->tag}@example.invalid";
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Mod')->setLastname($name)->setRoles($roles)->setPassword('inutilisable')->setIsVerified(true);
        $em->persist($user);
        $em->flush();

        return static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }

    private function site(): \PDO
    {
        return static::getContainer()->get(TenantDatabaseConnector::class)->pdo(MV_TEST_TENANT_DB);
    }
}
