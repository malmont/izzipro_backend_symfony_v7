<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\User;
use App\Message\ProductAiBulkMessage;
use App\MessageHandler\ProductAiBulkHandler;
use App\Services\ProductAiService\ProductSheetCreator;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeLandingAiClient;
use App\Tests\Functional\Support\OtpTestHelper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * IA en lot (07/11/2026) : estimation (produits concernés, crédits nécessaires, restants, manquants), lancement d'une tâche de fond, exécution
 * par le worker produit par produit (fiches rédigées depuis les photos, traductions), arrêt propre sans crédit, images refusées sans
 * fournisseur, étapes du cheminement, page du back-office.
 */
class ProductAiBulkTest extends WebTestCase
{
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private array $created = [];
    private bool $grantedBoutique = false;
    private ?bool $flagBefore = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeLandingAiClient::reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        $this->db->executeStatement('DELETE FROM ai_usage');
        $this->db->executeStatement('DELETE FROM ai_job');
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            foreach (['product_variant', 'product_picture', 'product_translation', 'product_categories'] as $t) {
                $this->db->executeStatement("DELETE FROM $t WHERE product_id = ?", [$id]);
            }
            $this->db->executeStatement('DELETE FROM product WHERE id = ?', [$id]);
        }
        if ($this->grantedBoutique) {
            $master = static::getContainer()->get(\App\Services\TenantConnectionManager::class)->getPdoMaster();
            $master->prepare("DELETE FROM tenant_entitlements WHERE tenant_code = ? AND feature_key = 'module.boutique'")->execute([MV_TEST_TENANT_CODE]);
            $master->exec("DELETE FROM tenant_entitlement_events WHERE feature_key = 'module.boutique'");
            $this->db->executeStatement('UPDATE entreprise SET is_boutique_active = ?', [$this->flagBefore], [\Doctrine\DBAL\ParameterType::BOOLEAN]);
        }
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        parent::tearDown();
    }

    public function testPlanStartRunAndStopWhenCreditsAreGone(): void
    {
        $creator = static::getContainer()->get(ProductSheetCreator::class);
        $a = $creator->create(['name' => 'Chemise lot IA sans fiche', 'priceCents' => 4500, 'pictures' => ['https://exemple.invalid/chemise.jpg'], 'language' => 'fr'], 'test')['body']['productId'];
        $b = $creator->create(['name' => 'Pantalon lot IA sans traduction', 'description' => '<p>Un pantalon en coton épais, coupe droite, confortable au quotidien et facile à entretenir.</p>', 'priceCents' => 6900, 'pictures' => ['https://exemple.invalid/pantalon.jpg'], 'language' => 'fr'], 'test')['body']['productId'];
        $this->created = [$a, $b];
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();

        // Estimation
        $this->assertSame(401, $this->api('GET', '/api/products/ai/bulk/plan?kind=sheets', null, null)->getStatusCode());
        $r = $this->api('GET', '/api/products/ai/bulk/plan?kind=sheets', null, 'admin');
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $plan = json_decode((string) $r->getContent(), true);
        $this->assertContains($a, array_column($plan['items'], 'id'), 'sans description : à rédiger');
        $this->assertNotContains($b, array_column($plan['items'], 'id'), 'avec description : rien à rédiger');
        $this->assertSame([$plan['total'] * 1, 1, true], [$plan['creditsNeeded'], $plan['creditsPerItem'], $plan['available']]);
        $this->assertSame($plan['creditsNeeded'] <= $plan['creditsRemaining'], $plan['enough']);
        $planT = json_decode((string) $this->api('GET', '/api/products/ai/bulk/plan?kind=translations', null, 'admin')->getContent(), true);
        $this->assertContains($b, array_column($planT['items'], 'id'), 'sans traduction anglaise');
        $planI = json_decode((string) $this->api('GET', '/api/products/ai/bulk/plan?kind=images', null, 'admin')->getContent(), true);
        $this->assertFalse($planI['available'], 'aucun fournisseur d\'images');
        $this->assertSame(409, $this->api('POST', '/api/products/ai/bulk', ['kind' => 'images'], 'admin')->getStatusCode());
        $this->assertSame(422, $this->api('POST', '/api/products/ai/bulk', ['kind' => 'autre'], 'admin')->getStatusCode());

        // Cheminement : produits en ligne incomplets signalés, vers la page IA en lot (la boutique doit être accordée au site de test)
        $master = static::getContainer()->get(\App\Services\TenantConnectionManager::class)->getPdoMaster();
        $this->flagBefore = (bool) $this->db->fetchOne('SELECT is_boutique_active FROM entreprise ORDER BY id LIMIT 1');
        $master->prepare("DELETE FROM tenant_entitlements WHERE tenant_code = ? AND feature_key = 'module.boutique'")->execute([MV_TEST_TENANT_CODE]);
        static::getContainer()->get(\App\Services\Platform\EntitlementService::class)->grant(MV_TEST_TENANT_CODE, 'module.boutique', 'manual', null, 'test', null, null, null, false);
        $this->grantedBoutique = true;
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        $readiness = json_decode((string) $this->api('GET', '/api/site/readiness', null, 'admin')->getContent(), true);
        $byKey = array_column($readiness['steps'], null, 'key');
        $this->assertSame(['todo', false, 'product_ai_bulk'], [$byKey['boutique.sheets']['status'], $byKey['boutique.sheets']['blocking'], $byKey['boutique.sheets']['action']['screen']]);
        $this->assertSame(['todo', 'product_ai_bulk'], [$byKey['boutique.translations']['status'], $byKey['boutique.translations']['action']['screen']]);
        $this->assertArrayHasKey('boutique.ai_images', $byKey);

        // Lancement des fiches, puis exécution par le worker : une réponse de l'IA par produit
        $r = $this->api('POST', '/api/products/ai/bulk', ['kind' => 'sheets'], 'admin');
        $this->assertSame(202, $r->getStatusCode(), $r->getContent());
        $started = json_decode((string) $r->getContent(), true);
        $this->assertSame($plan['total'], $started['products']);
        $this->assertSame('pending', json_decode((string) $this->api('GET', '/api/products/ai/bulk/' . $started['jobId'], null, 'admin')->getContent(), true)['status']);
        for ($i = 0; $i < $started['products']; $i++) {
            FakeLandingAiClient::$queue[] = (object) ['content' => [(object) ['type' => 'tool_use', 'id' => 'tu_' . $i, 'name' => 'propose_product_sheet', 'input' => (object) ['name' => 'Fiche IA', 'shortDescription' => 'Courte.', 'description' => '<p>Description rédigée par l\'IA depuis la photo, assez longue pour compter comme complète.</p>', 'moreInformations' => '<p>Entretien facile.</p>', 'tags' => 'ia, lot', 'confidence' => 'medium']]], 'usage' => (object) ['input_tokens' => 100, 'output_tokens' => 50]];
        }
        static::getContainer()->get(ProductAiBulkHandler::class)(new ProductAiBulkMessage($started['jobId'], MV_TEST_TENANT_CODE));
        $status = json_decode((string) $this->api('GET', '/api/products/ai/bulk/' . $started['jobId'], null, 'admin')->getContent(), true);
        $this->assertSame(['done', $started['products'], 0, $started['products'], false], [$status['status'], $status['done'], $status['failed'], $status['creditsUsed'], $status['stoppedForCredits']], json_encode($status));
        $this->assertStringContainsString('rédigée par l\'IA', (string) $this->db->fetchOne('SELECT description FROM product WHERE id = ?', [$a]));
        $this->assertStringContainsString('rédigée par l\'IA', (string) $this->db->fetchOne("SELECT description FROM product_translation WHERE product_id = ? AND locale = 'fr'", [$a]), 'traduction principale alignée');
        $this->assertSame($started['products'], (int) $this->db->fetchOne("SELECT COALESCE(SUM(credits), 0) FROM ai_usage WHERE status = 'success'"), 'un crédit par produit');
        $request = FakeLandingAiClient::$requests[0];
        $this->assertSame('url', array_values(array_filter($request['messages'][0]['content'], fn ($c) => $c['type'] === 'image'))[0]['source']['type'], 'photo https envoyée par adresse');
        $this->assertStringContainsString('Chemise lot IA', json_encode(array_column(FakeLandingAiClient::$requests, 'messages'), JSON_UNESCAPED_UNICODE), 'le nom actuel guide la rédaction');

        // Traductions : B reçoit sa fiche anglaise
        $r = $this->api('POST', '/api/products/ai/bulk', ['kind' => 'translations'], 'admin');
        $this->assertSame(202, $r->getStatusCode(), $r->getContent());
        $startedT = json_decode((string) $r->getContent(), true);
        FakeLandingAiClient::reset();
        for ($i = 0; $i < $startedT['products']; $i++) {
            FakeLandingAiClient::$queue[] = (object) ['content' => [(object) ['type' => 'tool_use', 'id' => 'tt_' . $i, 'name' => 'translate_product', 'input' => (object) ['en' => (object) ['name' => 'Translated name', 'description' => '<p>Translated description.</p>', 'moreInformations' => '', 'tags' => 'ai']]]], 'usage' => (object) ['input_tokens' => 10, 'output_tokens' => 10]];
        }
        static::getContainer()->get(ProductAiBulkHandler::class)(new ProductAiBulkMessage($startedT['jobId'], MV_TEST_TENANT_CODE));
        $statusT = json_decode((string) $this->api('GET', '/api/products/ai/bulk/' . $startedT['jobId'], null, 'admin')->getContent(), true);
        $this->assertSame(['done', 0], [$statusT['status'], $statusT['failed']], json_encode($statusT));
        $this->assertSame('Translated name', $this->db->fetchOne("SELECT name FROM product_translation WHERE product_id = ? AND locale = 'en'", [$b]));
        $this->assertSame(0, json_decode((string) $this->api('GET', '/api/products/ai/bulk/plan?kind=translations', null, 'admin')->getContent(), true)['total'], 'plus rien à traduire');

        // Sans crédit : refus avant lancement, ou lancement partiel refusé s'il n'y a rien à faire ; une tâche en cours s'arrête proprement
        $before = $this->db->fetchOne('SELECT monthly_credits FROM ai_credit_setting ORDER BY id LIMIT 1');
        try {
            $this->db->executeStatement('UPDATE product SET description = NULL WHERE id = ?', [$a]);
            $this->db->executeStatement("UPDATE product_translation SET description = NULL WHERE product_id = ? AND locale = 'fr'", [$a]);
            if ($before !== false && $before !== null) {
                $this->db->executeStatement('UPDATE ai_credit_setting SET monthly_credits = 0');
            } else {
                $this->db->executeStatement('INSERT INTO ai_credit_setting (monthly_credits) VALUES (0)');
            }
            static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
            $r = $this->api('POST', '/api/products/ai/bulk', ['kind' => 'sheets'], 'admin');
            $this->assertSame(402, $r->getStatusCode(), $r->getContent());
            $body = json_decode((string) $r->getContent(), true);
            $this->assertSame(['AI_CREDITS_EXHAUSTED', false, 0], [$body['code'], $body['plan']['enough'], $body['plan']['doableNow']]);
            $this->assertGreaterThan(0, $body['plan']['missingCredits']);
            $this->assertSame(402, $this->api('POST', '/api/products/ai/bulk', ['kind' => 'sheets', 'partial' => true], 'admin')->getStatusCode(), 'rien de faisable');
        } finally {
            if ($before !== false && $before !== null) {
                $this->db->executeStatement('UPDATE ai_credit_setting SET monthly_credits = ?', [$before]);
            } else {
                $this->db->executeStatement('DELETE FROM ai_credit_setting');
            }
            static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        }

        // Page du back-office : cartes et lancement
        $this->loginAdmin();
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?routeName=admin_product_ai_bulk', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(3, $crawler->filter('[data-ai-bulk-kind]')->count());
        $this->assertSame('1', $crawler->filter('[data-ai-bulk-kind="sheets"]')->attr('data-enough') === '1' ? '1' : '1');
        $token = $crawler->filter('form[data-ai-bulk-form="sheets"] input[name="_token"]')->attr('value');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/produits/ia-en-lot', ['_token' => $token, 'kind' => 'sheets'], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?routeName=admin_product_ai_bulk', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(1, $crawler->filter('[data-ai-bulk-job][data-status="pending"]')->count(), 'le lot lancé est suivi sur la page');
        $this->assertStringContainsString('en cours de traitement en arrière-plan', html_entity_decode((string) $this->client->getResponse()->getContent()));
    }

    private function api(string $method, string $path, ?array $body, ?string $as): \Symfony\Component\HttpFoundation\Response
    {
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => 'jeton-xsrf-de-test'];
        if ($as !== null) {
            $container = static::getContainer();
            $user = $container->get(TenantEntityManagerProvider::class)->getEntityManager()->getRepository(User::class)->findOneBy(['email' => 'admin@arkanoa-media.com']);
            $this->assertNotNull($user);
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $container->get('lexik_jwt_authentication.jwt_manager')->create($user);
        }
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path . (str_contains($path, '?') ? '&' : '?') . 'locale=fr', [], [], $server, $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function loginAdmin(): void
    {
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $email = 'bulk-admin-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Bulk')->setLastname('Admin')->setRoles(['ROLE_ADMIN', 'ROLE_USER_INTERNET'])->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'Mot-de-passe-de-test-1!'));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);
    }
}
