<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\ShippingService\ProductShippingResolver;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeLandingAiClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Livraison, lot 1 bis (07/11/2026) : poids et dimensions effectifs d'un produit (fiche, sinon classe, sinon site ; les colis d'un panier
 * ne cassent plus sans fiche), grille de saisie rapide, estimation par l'IA préremplie puis enregistrée, outil de l'assistant confirmé.
 */
class ShippingWeightsTest extends WebTestCase
{
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    /** @var list<int> articles créés par d'autres tests, mis de côté le temps de celui-ci (voir setUp) */
    private array $setAside = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-poids@example.invalid', false, false);
        // Contexte isolé (13/11/2026) : l'estimation ne prend que les premiers articles sans dimensions, classés par nom. La base de test
        // est partagée par toute la suite : un article laissé par un autre test pouvait passer devant ceux de démonstration, et le test
        // échouait dans la suite complète sans jamais échouer seul. Les articles qui ne sont pas de démonstration sortent donc du
        // périmètre (« ne s'expédie pas ») le temps du test, puis y reviennent.
        $this->setAside = array_map('intval', $this->db->fetchFirstColumn("SELECT id FROM product WHERE requires_shipping = true AND (code IS NULL OR code NOT LIKE 'DEMO-%')"));
        if ($this->setAside !== []) {
            $this->db->executeStatement('UPDATE product SET requires_shipping = false WHERE id IN (?)', [$this->setAside], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
        }
        $this->db->executeStatement('DELETE FROM product_shipping');
        $this->db->executeStatement('DELETE FROM boutique_setting');
        FakeLandingAiClient::reset();
        if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM ai_credit_setting') === 0) {
            $this->db->executeStatement("INSERT INTO ai_credit_setting (id, monthly_credits) VALUES (nextval('ai_credit_setting_id_seq'), 50)");
        } else {
            $this->db->executeStatement('UPDATE ai_credit_setting SET monthly_credits = 50');
        }
    }

    protected function tearDown(): void
    {
        if ($this->setAside !== []) {
            $this->db->executeStatement('UPDATE product SET requires_shipping = true WHERE id IN (?)', [$this->setAside], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
            $this->setAside = [];
        }
        parent::tearDown();
    }

    public function testAProductWithoutASheetFallsBackToItsClassThenToTheSiteDefault(): void
    {
        $casquette = $this->productId('DEMO-CASQUETTE');
        $resolver = static::getContainer()->get(ProductShippingResolver::class);
        $r = $resolver->resolve($casquette);
        $this->assertSame(['site', 0.5, 20.0], [$r['source'], $r['item']->getWeightKg(), $r['item']->getLengthCm()], 'poids par défaut du site et dimensions types');

        // Les colis d'un panier se calculent sans fiche (avant : erreur « aucune configuration d'expédition »)
        $bearer = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($this->userByEmail('admin@arkanoa-media.com'));
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/shipping/parcels', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $bearer], json_encode(['cartItems' => [['productId' => $casquette, 'quantity' => 2]]]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $parcels = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertNotEmpty($parcels);
        $this->assertGreaterThan(0, (float) $parcels[0]['weight']);

        // Fiche sans poids mais classée « Volumineux » : valeurs de la classe
        $class = (int) $this->db->fetchOne("SELECT id FROM shipping_class WHERE name = 'Volumineux'");
        $this->assertGreaterThan(0, $class, 'classes fournies d\'office');
        $this->db->executeStatement('INSERT INTO product_shipping (id, product_id, shipping_class_entity_id, weight, length) VALUES (nextval(\'product_shipping_id_seq\'), ?, ?, 0, 0)', [$casquette, $class]);
        static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->clear();
        $r = $resolver->resolve($casquette);
        $this->assertSame(['class', 8.0, 60.0, 40.0, 40.0], [$r['source'], $r['item']->getWeightKg(), $r['item']->getLengthCm(), $r['item']->getWidthCm(), $r['item']->getHeightCm()]);
    }

    public function testTheGridSavesWeightsAndTheAiPrefillsIt(): void
    {
        $this->loginAdmin();
        $casquette = $this->productId('DEMO-CASQUETTE');
        $panier = $this->productId('DEMO-PANIER');
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/livraison/poids');
        $html = html_entity_decode((string) $this->client->getResponse()->getContent());
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString("data-product-row=\"$casquette\"", $html, 'produit sans poids listé');
        $this->assertStringContainsString('poids par défaut du site', $html);
        $token = $crawler->filter('form[data-poids-form] input[name="_token"]')->attr('value');

        // Saisie refusée : rien n'est écrit
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/livraison/poids', ['_token' => $token, 'action' => 'save', 'rows' => [$casquette => ['weightKg' => '-2']]]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->client->followRedirect();
        $this->assertStringContainsString('Rien n\'a été enregistré', html_entity_decode((string) $this->client->getResponse()->getContent()));
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM product_shipping'));

        // Saisie acceptée : poids, dimensions de la classe pour celles laissées vides, ligne sans poids ignorée
        $fragile = (int) $this->db->fetchOne("SELECT id FROM shipping_class WHERE name = 'Fragile'");
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/livraison/poids', ['_token' => $token, 'action' => 'save', 'rows' => [
            $casquette => ['weightKg' => '0,35', 'lengthCm' => '', 'widthCm' => '', 'heightCm' => '', 'shippingClassId' => (string) $fragile],
            $panier => ['weightKg' => '', 'lengthCm' => '', 'widthCm' => '', 'heightCm' => '', 'shippingClassId' => ''],
        ]]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $row = $this->db->fetchAssociative('SELECT weight, length, width, height, shipping_class_entity_id FROM product_shipping WHERE product_id = ?', [$casquette]);
        $this->assertSame([0.35, 30.0, 25.0, 15.0, $fragile], [(float) $row['weight'], (float) $row['length'], (float) $row['width'], (float) $row['height'], (int) $row['shipping_class_entity_id']]);
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM product_shipping'), 'la ligne sans poids n\'a rien créé');
        $this->client->followRedirect();
        $this->assertStringNotContainsString("data-product-row=\"$casquette\"", (string) $this->client->getResponse()->getContent(), 'sorti de la liste des produits sans poids');
        $this->assertSame('bulk_grid', $this->db->fetchOne("SELECT action FROM content_audit_log WHERE resource = 'product_shipping' ORDER BY id DESC LIMIT 1"));

        // Estimation par l'IA : propositions préremplies en surbrillance, un crédit consommé, rien d'enregistré avant « Enregistrer »
        $usedBefore = static::getContainer()->get(LandingAiQuotaService::class)->credits()['used'];
        FakeLandingAiClient::$queue[] = (object) ['content' => [(object) ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'propose_dimensions', 'input' => (object) ['items' => [
            (object) ['productId' => $panier, 'weightKg' => 1.2, 'lengthCm' => 10, 'widthCm' => 40, 'heightCm' => 30, 'shippingClass' => 'Standard', 'confidence' => 'medium', 'note' => 'Panier en osier moyen'],
            (object) ['productId' => 999999, 'weightKg' => 1, 'lengthCm' => 1, 'widthCm' => 1, 'heightCm' => 1, 'confidence' => 'high'],
        ]]]], 'usage' => (object) ['input_tokens' => 800, 'output_tokens' => 120]];
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/livraison/poids', ['_token' => $token, 'action' => 'estimate']);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $crawler = $this->client->followRedirect();
        $html = html_entity_decode((string) $this->client->getResponse()->getContent());
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('1 proposition(s) de l\'IA préremplies', $html, 'le produit inconnu est ignoré');
        $this->assertStringContainsString('Panier en osier moyen', $html);
        $this->assertSame('40', $crawler->filter("input[name=\"rows[$panier][lengthCm]\"]")->attr('value'), 'dimensions remises dans l\'ordre longueur ≥ largeur ≥ hauteur');
        $this->assertSame('1.2', $crawler->filter("input[name=\"rows[$panier][weightKg]\"]")->attr('value'));
        $this->assertSame($usedBefore + 1, static::getContainer()->get(LandingAiQuotaService::class)->credits()['used'], 'un crédit');
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM product_shipping'), 'rien d\'enregistré avant confirmation');
        $request = end(FakeLandingAiClient::$requests);
        $this->assertSame(['type' => 'auto'], $request['tool_choice'], 'Sonnet 5.5 refuse l\'outil forcé : auto + consigne');
        $this->assertStringContainsString('propose_dimensions', (string) $request['system'], 'la consigne nomme l\'outil');
        $this->assertStringContainsString('Produits à estimer', json_encode($request['messages'][0]['content'], JSON_UNESCAPED_UNICODE) ?: '', 'produits décrits dans la demande');
    }

    public function testTheAssistantEstimatesAndTheConfirmationWritesTheSheets(): void
    {
        $panier = $this->productId('DEMO-PANIER');
        $admin = $this->userByEmail('admin@arkanoa-media.com');
        $bearer = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($admin);
        $api = function (string $method, string $path, ?array $body) use ($bearer) {
            $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path . '?locale=fr', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => 'x', 'HTTP_AUTHORIZATION' => 'Bearer ' . $bearer], $body !== null ? json_encode($body) : null);

            return $this->client->getResponse();
        };
        // 1er tour : l'assistant appelle l'outil ; l'estimateur répond ; 2e tour : l'assistant conclut
        FakeLandingAiClient::$queue[] = (object) ['content' => [(object) ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'estimate_product_dimensions', 'input' => (object) ['limit' => 10]]], 'usage' => (object) ['input_tokens' => 500, 'output_tokens' => 30]];
        FakeLandingAiClient::$queue[] = (object) ['content' => [(object) ['type' => 'tool_use', 'id' => 'tu_e', 'name' => 'propose_dimensions', 'input' => (object) ['items' => [
            (object) ['productId' => $panier, 'weightKg' => 1.1, 'lengthCm' => 38, 'widthCm' => 28, 'heightCm' => 20, 'shippingClass' => 'Volumineux', 'confidence' => 'high', 'note' => 'Panier'],
        ]]]], 'usage' => (object) ['input_tokens' => 700, 'output_tokens' => 100]];
        FakeLandingAiClient::$queue[] = (object) ['content' => [(object) ['type' => 'text', 'text' => 'Je propose 1,1 kg pour le panier ; confirmez pour enregistrer.']], 'usage' => (object) ['input_tokens' => 900, 'output_tokens' => 40]];
        $r = $api('POST', '/api/site/assistant', ['messages' => [['role' => 'user', 'content' => 'Estime les poids de mes produits']]]);
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $body = json_decode((string) $r->getContent(), true);
        $this->assertCount(1, $body['actions'], $body['reply']);
        $this->assertSame('apply_product_dimensions', $body['actions'][0]['tool']);
        $this->assertSame([['label' => 'Poids et dimensions des produits (grille de saisie, estimation par l\'IA)', 'screen' => 'product_weights']], $body['links']);
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM product_shipping'), 'rien avant confirmation');

        $c = $api('POST', '/api/site/assistant/actions/' . $body['actions'][0]['id'] . '/confirm', []);
        $this->assertSame(200, $c->getStatusCode(), $c->getContent());
        $this->assertSame(1, json_decode((string) $c->getContent(), true)['result']['saved']);
        $volumineux = (int) $this->db->fetchOne("SELECT id FROM shipping_class WHERE name = 'Volumineux'");
        $row = $this->db->fetchAssociative('SELECT weight, length, shipping_class_entity_id FROM product_shipping WHERE product_id = ?', [$panier]);
        $this->assertSame([1.1, 38.0, $volumineux], [(float) $row['weight'], (float) $row['length'], (int) $row['shipping_class_entity_id']]);
        $this->assertSame('bulk_assistant', $this->db->fetchOne("SELECT action FROM content_audit_log WHERE resource = 'product_shipping' ORDER BY id DESC LIMIT 1"));
    }

    private function productId(string $code): int
    {
        $id = (int) $this->db->fetchOne('SELECT id FROM product WHERE code = ?', [$code]);
        $this->assertGreaterThan(0, $id, "produit de démonstration $code");

        return $id;
    }

    private function userByEmail(string $email): User
    {
        $user = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->getRepository(User::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($user);

        return $user;
    }

    private function loginAdmin(): void
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $email = 'poids-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Admin')->setLastname('Poids')->setRoles(['ROLE_USER', 'ROLE_USER_INTERNET', 'ROLE_ADMIN'])->setIsVerified(true)->setPassword('x');
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->setServerParameter('HTTP_X_TENANT_HOST', MV_TEST_TENANT_HOST);
        $this->client->loginUser($user, 'main');
        \App\Tests\Functional\Support\OtpTestHelper::validateIfRequired($this->client, $user);
    }
}
