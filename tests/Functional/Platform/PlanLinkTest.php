<?php

namespace App\Tests\Functional\Platform;

use App\Entity\SubscriptionPlan;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\Platform\PlanLink\PlatformPlanLinkService;
use App\Services\Platform\PlatformModeService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeSubscriptionStripeGateway;
use App\Tests\Functional\Support\OtpTestHelper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lien prouvé entre l'acheteur d'un forfait et le site servi, lot PROVISION-1 (18/11/2026) : « Mettre à jour mon forfait » émet un lien
 * signé de 30 minutes, qui ne sert qu'à UN achat (un nouvel essai de paiement du même achat est accepté) ; une formule branchée l'exige ; le
 * compte doit être encore administrateur du site ; un lien falsifié, expiré, inconnu ou d'un autre achat est refusé ; le code tapé n'est plus
 * accepté que d'un super administrateur ; une formule branchée n'a jamais d'essai Stripe ; le jeton n'est écrit dans aucun journal ni
 * aucune table.
 */
class PlanLinkTest extends WebTestCase
{
    private const SHOP_URL = 'https://boutique-plateforme.example.invalid/forfaits?source=console#formules'; // une ancre déjà là : remplacée
    private KernelBrowser $client;
    private TenantEntityManagerProvider $provider;
    private \PDO $master;
    private int $planId;
    private array $planBefore = [];
    private string $tag;
    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $this->tag = bin2hex(random_bytes(3));
        FakeSubscriptionStripeGateway::reset();
        $platform = static::getContainer()->get(PlatformModeService::class);
        $platform->setShopTenant(MV_TEST_TENANT_CODE, 'test'); // le site de test est la boutique de la plateforme, et aussi le site servi
        $platform->setShopUrl(self::SHOP_URL, 'test');
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-forfait@example.invalid', false, false);
        $db = $this->provider->getEntityManager()->getConnection();
        $db->executeStatement('DELETE FROM boutique_setting');
        $this->planId = (int) $db->fetchOne('SELECT sp.id FROM subscription_plan sp JOIN product p ON p.id = sp.product_id WHERE p.code = ? ORDER BY sp.id LIMIT 1', ['DEMO-PANIER']);
        $this->planBefore = $db->fetchAssociative('SELECT fulfillment_type, fulfillment_ref, trial_days FROM subscription_plan WHERE id = ?', [$this->planId]);
        $db->executeStatement("UPDATE subscription_plan SET fulfillment_type = ?, fulfillment_ref = 'boussole', trial_days = 14 WHERE id = ?", [SubscriptionPlan::FULFILLMENT_PLATFORM_OFFER, $this->planId]);
        $this->master->exec('DELETE FROM platform_plan_link');
    }

    protected function tearDown(): void
    {
        $db = $this->provider->getEntityManager()->getConnection();
        $db->executeStatement('DELETE FROM subscription WHERE fulfillment_target IS NOT NULL');
        $db->executeStatement('UPDATE subscription_plan SET fulfillment_type = ?, fulfillment_ref = ?, trial_days = ? WHERE id = ?', [$this->planBefore['fulfillment_type'], $this->planBefore['fulfillment_ref'], $this->planBefore['trial_days'], $this->planId]);
        $this->master->exec('DELETE FROM platform_plan_link');
        $this->master->exec("DELETE FROM tenant_entitlement_events WHERE entitlement_id IN (SELECT id FROM tenant_entitlements WHERE source = 'subscription')");
        $this->master->exec("DELETE FROM tenant_entitlements WHERE source = 'subscription'");
        $platform = static::getContainer()->get(PlatformModeService::class);
        $platform->setShopTenant(null, 'test');
        $platform->setShopUrl(null, 'test');
        \App\Tests\Functional\Support\AiRightTestHelper::restoreFullAi(static::getContainer());
        parent::tearDown();
    }

    public function testTheLinkFromMyPlanServesOnePurchaseOfItsOwnSiteOnly(): void
    {
        $logs = static::getContainer()->getParameter('kernel.logs_dir') . '/test.log';
        $logStart = is_file($logs) ? filesize($logs) : 0;
        // « Mettre à jour mon forfait » : la boutique reçoit le lien signé, sans cache ni adresse d'origine transmise
        $admin = $this->user('admin', ['ROLE_ADMIN']);
        $this->client->loginUser($admin, 'main');
        OtpTestHelper::validateIfRequired($this->client, $admin);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/mon-site/forfait', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $redirect = $this->client->getResponse();
        $this->assertTrue($redirect->isRedirection(), (string) $redirect->getContent());
        // le lien en ANCRE (jamais envoyé à un serveur, donc dans aucun journal d'accès) ; l'ancre d'origine est remplacée
        $this->assertSame(1, preg_match('#^' . preg_quote('https://boutique-plateforme.example.invalid/forfaits?source=console', '#') . '\#lien=(pl1\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+)$#', (string) $redirect->headers->get('Location'), $m));
        $link = rawurldecode($m[1]);
        $this->assertSame('no-referrer', $redirect->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('no-store', (string) $redirect->headers->get('Cache-Control'));
        $row = $this->master->query('SELECT * FROM platform_plan_link')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame([MV_TEST_TENANT_CODE, $admin->getId()], [$row['tenant_code'], (int) $row['user_id']]);
        $this->assertEqualsWithDelta(PlatformPlanLinkService::TTL_SECONDS, strtotime($row['expires_at'] . ' UTC') - strtotime($row['issued_at'] . ' UTC'), 1, '30 minutes');
        // une formule branchée exige le lien ; le code tapé ne vaut plus que d'un super administrateur
        $this->assertSame('FULFILLMENT_LINK_REQUIRED', $this->subscribe('client', ['fulfillmentTarget' => MV_TEST_TENANT_CODE], 422)['code']);
        $this->assertSame('FULFILLMENT_LINK_REQUIRED', $this->subscribe('client', [], 422)['code']);
        // l'achat avec le lien : le site servi est celui du lien ; pas d'essai Stripe sur une formule branchée
        $first = $this->subscribe('client', ['fulfillmentLink' => $link], 201);
        $this->assertSame(MV_TEST_TENANT_CODE, $this->target($first['subscriptionId']));
        $created = array_values(array_filter(FakeSubscriptionStripeGateway::$calls, fn ($c) => $c[0] === 'create'));
        $this->assertSame(0, end($created)[4], 'formule branchée : aucun jour d\'essai envoyé à Stripe');
        $listed = $this->json('GET', '/api/subscription-plans', null, 'client', 200);
        $plan = array_values(array_filter($listed['plans'] ?? $listed, fn ($p) => is_array($p) && ($p['id'] ?? null) === $this->planId));
        $this->assertCount(1, $plan);
        $this->assertSame(0, $plan[0]['trialDays'], 'la formule branchée s\'affiche sans essai (14 jours réglés sur la formule)');
        // un nouvel essai de paiement du même achat est accepté ; le lien garde la preuve de l'abonnement
        $again = $this->subscribe('client', ['fulfillmentLink' => $link], 200);
        $this->assertSame($first['subscriptionId'], $again['subscriptionId']);
        $proof = $this->master->query('SELECT * FROM platform_plan_link')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame([MV_TEST_TENANT_CODE, $first['subscriptionId']], [$proof['shop_tenant'], (int) $proof['subscription_id']]);
        // un autre acheteur avec le même lien : refusé
        $this->assertSame('FULFILLMENT_LINK_USED', $this->subscribe('other', ['fulfillmentLink' => $link], 422)['code']);
        // falsifié, signé avec une autre clé, inconnu, expiré : refusés
        [$prefix, $body, $signature] = explode('.', $link);
        $claims = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
        $this->assertSame('FULFILLMENT_LINK_INVALID', $this->subscribe('other', ['fulfillmentLink' => $prefix . '.' . self::b64((string) json_encode(['s' => 'autre-site'] + $claims)) . '.' . $signature], 422)['code']);
        $this->assertSame('FULFILLMENT_LINK_INVALID', $this->subscribe('other', ['fulfillmentLink' => 'n-importe-quoi'], 422)['code']);
        // le vrai contenu avec une fausse signature : refusé pour la signature, avant même la base
        $this->assertSame('FULFILLMENT_LINK_INVALID', $this->subscribe('other', ['fulfillmentLink' => $prefix . '.' . $body . '.' . self::b64(random_bytes(32))], 422)['code']);
        $this->assertSame('FULFILLMENT_LINK_INVALID', $this->subscribe('other', ['fulfillmentLink' => $this->forge(['j' => str_repeat('a', 32)] + $claims)], 422)['code'], 'identifiant inconnu');
        $expired = ['j' => str_repeat('b', 32), 'e' => time() - 1] + $claims;
        $this->master->prepare('INSERT INTO platform_plan_link (jti, tenant_code, user_id, issued_at, expires_at) VALUES (?, ?, ?, NOW(), NOW())')->execute([$expired['j'], MV_TEST_TENANT_CODE, $admin->getId()]);
        $out = $this->subscribe('other', ['fulfillmentLink' => $this->forge($expired)], 422);
        $this->assertSame('FULFILLMENT_LINK_EXPIRED', $out['code']);
        $this->assertStringContainsString('Mettre à jour mon forfait', $out['message']);
        // un lien émis par un compte qui n'administre plus le site : refusé
        $fresh = static::getContainer()->get(PlatformPlanLinkService::class)->issue(MV_TEST_TENANT_CODE, $admin->getId());
        $this->provider->getEntityManager()->getConnection()->executeStatement('UPDATE "user" SET roles = ? WHERE id = ?', ['["ROLE_USER_INTERNET"]', $admin->getId()]);
        $this->assertSame('FULFILLMENT_LINK_NOT_ADMIN', $this->subscribe('other', ['fulfillmentLink' => $fresh], 422)['code']);
        // vente faite à la main : le super administrateur peut encore viser un site par son code
        $this->assertSame('FULFILLMENT_TARGET_UNKNOWN', $this->subscribe('super', ['fulfillmentTarget' => 'site-inconnu'], 422)['code']);
        $this->assertSame(MV_TEST_TENANT_CODE, $this->target($this->subscribe('super', ['fulfillmentTarget' => MV_TEST_TENANT_CODE], 201)['subscriptionId']));
        // le jeton n'est gardé dans aucune table de la base maître, ni écrit dans le journal de l'application
        $this->assertStringNotContainsString($signature, (string) json_encode($this->master->query('SELECT * FROM platform_plan_link')->fetchAll(\PDO::FETCH_ASSOC)));
        $written = is_file($logs) ? (string) file_get_contents($logs, false, null, $logStart) : '';
        $this->assertStringNotContainsString($signature, $written, 'aucun journal ne porte le jeton');
        $this->assertStringNotContainsString($body, $written);
    }

    /**
     * Relecture neuve : le site servi distinct de la boutique ; un abonnement incomplet repris garde son site (visé ailleurs, il est remplacé) ;
     * le même acheteur pour une autre formule ; un compte non vérifié ou supprimé ; un lien employé hors de la boutique de la plateforme n'est
     * pas « brûlé » ; 30 liens par heure et par compte.
     */
    public function testTheServedSiteIsTheLinksAndTheEdgesHold(): void
    {
        $links = static::getContainer()->get(PlatformPlanLinkService::class);
        $second = static::getContainer()->get(\App\Services\Platform\TenantDatabaseConnector::class)->pdo(MV_TEST_TENANT2_DB);
        $email = "forfait-x2-$this->tag@example.invalid";
        $insert = $second->prepare("INSERT INTO \"user\" (id, email, roles, password, username, firstname, lastname, is_verified, password_set, otp_enabled, created_at) VALUES (nextval('user_id_seq'), ?, '[\"ROLE_ADMIN\"]', 'inutilisable', ?, 'Forfait', 'Second', true, true, false, NOW()) RETURNING id");
        $insert->execute([$email, $email]);
        $adminY = (int) $insert->fetchColumn();
        try {
            $admin = $this->user('admin', ['ROLE_ADMIN']);
            $first = $this->subscribe('client', ['fulfillmentLink' => $links->issue(MV_TEST_TENANT_CODE, $admin->getId())], 201);
            // un lien d'un AUTRE site : le site servi est celui du lien, pas la boutique ; l'abonnement incomplet visé sur X est remplacé
            $onY = $this->subscribe('client', ['fulfillmentLink' => $links->issue(MV_TEST_TENANT2_CODE, $adminY)], 201);
            $this->assertNotSame($first['subscriptionId'], $onY['subscriptionId']);
            $this->assertSame(MV_TEST_TENANT2_CODE, $this->target($onY['subscriptionId']));
            $this->assertSame('canceled', $this->provider->getEntityManager()->getConnection()->fetchOne('SELECT status FROM subscription WHERE id = ?', [$first['subscriptionId']]));
            // le même acheteur, une autre formule : le lien a déjà servi
            $used = $links->issue(MV_TEST_TENANT_CODE, $admin->getId());
            $this->subscribe('other', ['fulfillmentLink' => $used], 201);
            $db = $this->provider->getEntityManager()->getConnection();
            $otherPlan = (int) $db->fetchOne('SELECT id FROM subscription_plan WHERE id <> ? ORDER BY id LIMIT 1', [$this->planId]);
            $this->assertGreaterThan(0, $otherPlan);
            $before = $db->fetchAssociative('SELECT fulfillment_type, fulfillment_ref FROM subscription_plan WHERE id = ?', [$otherPlan]);
            $db->executeStatement("UPDATE subscription_plan SET fulfillment_type = ?, fulfillment_ref = 'boussole' WHERE id = ?", [SubscriptionPlan::FULFILLMENT_PLATFORM_OFFER, $otherPlan]);
            try {
                $this->assertSame('FULFILLMENT_LINK_USED', $this->json('POST', '/api/subscriptions', ['planId' => $otherPlan, 'fulfillmentLink' => $used], 'other', 422)['code']);
            } finally {
                $db->executeStatement('UPDATE subscription_plan SET fulfillment_type = ?, fulfillment_ref = ? WHERE id = ?', [$before['fulfillment_type'], $before['fulfillment_ref'], $otherPlan]);
            }
            // un compte non vérifié, puis supprimé : plus administrateur
            $notVerified = $links->issue(MV_TEST_TENANT2_CODE, $adminY);
            $second->prepare('UPDATE "user" SET is_verified = false WHERE id = ?')->execute([$adminY]);
            $this->assertSame('FULFILLMENT_LINK_NOT_ADMIN', $this->subscribe('third', ['fulfillmentLink' => $notVerified], 422)['code']);
            $second->prepare('UPDATE "user" SET is_verified = true, deleted_at = NOW() WHERE id = ?')->execute([$adminY]);
            $this->assertSame('FULFILLMENT_LINK_NOT_ADMIN', $this->subscribe('third', ['fulfillmentLink' => $notVerified], 422)['code']);
            // hors de la boutique de la plateforme, le lien est refusé SANS être employé
            $kept = $links->issue(MV_TEST_TENANT_CODE, $admin->getId());
            static::getContainer()->get(PlatformModeService::class)->setShopTenant(MV_TEST_TENANT2_CODE, 'test');
            $this->assertSame('FULFILLMENT_LINK_INVALID', $this->subscribe('fourth', ['fulfillmentLink' => $kept], 422)['code']);
            static::getContainer()->get(PlatformModeService::class)->setShopTenant(MV_TEST_TENANT_CODE, 'test');
            $this->subscribe('fourth', ['fulfillmentLink' => $kept], 201); // le lien n'a pas été brûlé
            // 30 liens par heure et par compte
            $this->master->exec('DELETE FROM platform_plan_link');
            for ($i = 0; $i < PlatformPlanLinkService::ISSUE_PER_HOUR; ++$i) {
                $links->issue(MV_TEST_TENANT_CODE, $admin->getId());
            }
            try {
                $links->issue(MV_TEST_TENANT_CODE, $admin->getId());
                $this->fail('le 31e lien de l\'heure est refusé');
            } catch (\App\Services\Platform\PlanLink\PlanLinkException $e) {
                $this->assertSame('FULFILLMENT_LINK_RATE', $e->errorCode);
            }
        } finally {
            $second->prepare('DELETE FROM "user" WHERE id = ?')->execute([$adminY]);
        }
    }

    private function forge(array $claims): string
    {
        $key = hash_hmac('sha256', 'platform-plan-link', (string) static::getContainer()->getParameter('kernel.secret'), true);
        $body = self::b64((string) json_encode($claims));

        return 'pl1.' . $body . '.' . self::b64(hash_hmac('sha256', 'pl1.' . $body, $key, true));
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function target(int $subscriptionId): ?string
    {
        $value = $this->provider->getEntityManager()->getConnection()->fetchOne('SELECT fulfillment_target FROM subscription WHERE id = ?', [$subscriptionId]);

        return $value === false ? null : $value;
    }

    /** @return array<string, mixed> */
    private function subscribe(string $as, array $extra, int $expected): array
    {
        return $this->json('POST', '/api/subscriptions', ['planId' => $this->planId] + $extra, $as, $expected);
    }

    /** @return array<string, mixed> */
    private function json(string $method, string $path, ?array $body, string $as, int $expected): array
    {
        $this->client->getCookieJar()->clear();
        $roles = ['client' => ['ROLE_USER_INTERNET'], 'other' => ['ROLE_USER_INTERNET'], 'third' => ['ROLE_USER_INTERNET'], 'fourth' => ['ROLE_USER_INTERNET'], 'super' => ['ROLE_USER_INTERNET', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN']][$as];
        $token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($this->user($as, $roles));
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token], $body !== null ? json_encode($body) : null);
        $response = $this->client->getResponse();
        $this->assertSame($expected, $response->getStatusCode(), "$method $path ($as) : " . substr((string) $response->getContent(), 0, 500));

        return json_decode((string) $response->getContent(), true) ?? [];
    }

    private function user(string $as, array $roles): User
    {
        if (isset($this->users[$as])) {
            return $this->users[$as];
        }
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $this->provider->getEntityManager();
        $email = "forfait-$as-$this->tag@example.invalid";
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Forfait')->setLastname(ucfirst($as))->setRoles($roles)->setPassword('inutilisable')->setIsVerified(true);
        $em->persist($user);
        $em->flush();

        return $this->users[$as] = $user;
    }
}
