<?php

namespace App\Tests\Functional\Boutique;

use App\Controller\Admin\ReviewCrudController;
use App\Entity\ReviewsProduct;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\ReviewService\ReviewModerationService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Avis clients (09/10/2026) : achat vérifié exigé par défaut, modération avant publication, un avis par client et par
 * produit, texte en clair, moyenne et nombre exposés sur le produit, réglages du site, écrans d'administration.
 */
class ReviewApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';

    private KernelBrowser $client;
    private array $session = [];
    private \Doctrine\DBAL\Connection $db;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-avis@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM reviews_product');
        $this->db->executeStatement('DELETE FROM review_setting');
        $this->db->executeStatement('UPDATE product SET rating_average = NULL, rating_count = 0');
    }

    protected function tearDown(): void
    {
        $this->db->executeStatement('DELETE FROM review_setting');
        parent::tearDown();
    }

    public function testVerifiedBuyerReviewIsModeratedPublishedAnsweredEditedAndDeleted(): void
    {
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET'], 'Marie', 'dupont'); // membre du personnel : commande sans Stripe
        $product = $this->productId('DEMO-CASQUETTE');

        $eligibility = $this->json($this->request('GET', "/api/products/$product/reviews/eligibility"));
        $this->assertSame([false, 'not_purchased', false], [$eligibility['canReview'], $eligibility['reason'], $eligibility['verifiedPurchase']]);
        $refused = $this->request('POST', "/api/products/$product/reviews", json_encode(['rating' => 5, 'body' => 'Très bonne casquette, je recommande.']));
        $this->assertSame(403, $refused->getStatusCode(), 'achat exigé par défaut');
        $this->assertSame('not_purchased', json_decode($refused->getContent(), true)['reason']);

        $this->buy('DEMO-CASQUETTE');
        $eligibility = $this->json($this->request('GET', "/api/products/$product/reviews/eligibility"));
        $this->assertSame([true, null, true, 20], [$eligibility['canReview'], $eligibility['reason'], $eligibility['verifiedPurchase'], $eligibility['minLength']]);

        $invalid = $this->request('POST', "/api/products/$product/reviews", json_encode(['rating' => 6, 'body' => 'Bof']));
        $this->assertSame(422, $invalid->getStatusCode());
        $this->assertContains('rating', array_column(json_decode($invalid->getContent(), true)['errors'], 'path'));
        $short = $this->request('POST', "/api/products/$product/reviews", json_encode(['rating' => 4, 'body' => 'Trop court']));
        $this->assertSame(422, $short->getStatusCode(), '20 caractères au moins par défaut');

        $created = $this->request('POST', "/api/products/$product/reviews", json_encode([
            'rating' => 4, 'title' => '<b>Bonne</b> casquette', 'body' => "<script>alert(1)</script>Tissu agréable,\n\n\n\ntaille un peu petite.",
        ]));
        $this->assertSame(201, $created->getStatusCode(), $created->getContent());
        $body = json_decode($created->getContent(), true);
        $this->assertSame('pending', $body['status'], 'modération avant publication par défaut');
        $this->assertSame(['Bonne casquette', "alert(1)Tissu agréable,\n\ntaille un peu petite.", 'Marie D.', true], [$body['review']['title'], $body['review']['body'], $body['review']['author'], $body['review']['verifiedPurchase']]);
        $id = $body['review']['id'];
        $this->assertSame(409, $this->request('POST', "/api/products/$product/reviews", json_encode(['rating' => 5, 'body' => 'Un second avis sur le même produit.']))->getStatusCode());

        $list = $this->json($this->request('GET', "/api/products/$product/reviews"));
        $this->assertSame([0, null, []], [$list['summary']['count'], $list['summary']['average'], $list['items']], 'un avis en attente n\'est pas public');

        $moderation = static::getContainer()->get(ReviewModerationService::class);
        $moderation->approve($this->review($id));
        $moderation->reply($this->review($id), 'Merci <i>Marie</i> ! Une taille au-dessus existe.');
        $list = $this->json($this->request('GET', "/api/products/$product/reviews?sort=highest"));
        $this->assertSame(1, $list['summary']['count']);
        $this->assertSame(4.0, (float) $list['summary']['average']);
        $this->assertSame(['5' => 0, '4' => 1, '3' => 0, '2' => 0, '1' => 0], array_map('intval', $list['summary']['distribution']));
        $this->assertSame([$id, 'Merci Marie ! Une taille au-dessus existe.'], [$list['items'][0]['id'], $list['items'][0]['reply']['body']]);
        $this->assertArrayNotHasKey('status', array_filter($list['items'][0], fn ($v) => $v !== null), 'le statut ne sort que pour l\'auteur');
        $detail = $this->json($this->request('GET', "/api/products/$product"));
        $this->assertSame([4.0, 1], [(float) $detail['rating'], $detail['reviewCount']], 'moyenne et nombre sur le produit');

        $updated = $this->json($this->request('PUT', "/api/reviews/$id", json_encode(['rating' => 2])));
        $this->assertSame(['pending', 2, 'Bonne casquette'], [$updated['status'], $updated['review']['rating'], $updated['review']['title']], 'modifié : repasse en modération, champs non envoyés gardés');
        $detail = $this->json($this->request('GET', "/api/products/$product"));
        $this->assertSame([null, 0], [$detail['rating'], $detail['reviewCount']]);
        $mine = $this->json($this->request('GET', '/api/reviews/mine'));
        $this->assertSame([$id, 'pending', $product], [$mine[0]['id'], $mine[0]['status'], $mine[0]['product']['id']]);

        $owner = $this->session;
        $this->loginAs(['ROLE_USER_INTERNET'], 'Autre', 'Client');
        $this->assertSame(404, $this->request('PUT', "/api/reviews/$id", json_encode(['rating' => 1]))->getStatusCode(), 'avis d\'un autre client');
        $this->assertSame(404, $this->request('DELETE', "/api/reviews/$id")->getStatusCode());
        $this->session = $owner;
        $this->assertSame(204, $this->request('DELETE', "/api/reviews/$id")->getStatusCode());
        $this->assertSame([], $this->json($this->request('GET', '/api/reviews/mine')));
    }

    public function testSiteSettingsOpenReviewsToAllCustomersPublishImmediatelyOrDisableThem(): void
    {
        $this->db->executeStatement("INSERT INTO review_setting (enabled, verified_only, moderation, min_length, policy, updated_at) VALUES (true, false, 'auto', 5, '{\"fr\": \"Avis publiés sans tri.\", \"en\": \"Unfiltered reviews.\"}', NOW())");
        $this->loginAs(['ROLE_USER_INTERNET'], 'Léa', '');
        $product = $this->productId('DEMO-GOURDE');

        $created = $this->request('POST', "/api/products/$product/reviews", json_encode(['rating' => 5, 'body' => 'Parfait']));
        $this->assertSame(201, $created->getStatusCode(), $created->getContent());
        $body = json_decode($created->getContent(), true);
        $this->assertSame(['approved', false, 'Léa'], [$body['status'], $body['review']['verifiedPurchase'], $body['review']['author']]);
        $list = $this->json($this->request('GET', "/api/products/$product/reviews?locale=en"));
        $this->assertSame([1, 'Unfiltered reviews.', false], [$list['summary']['count'], $list['policy'], $list['verifiedOnly']]);

        $this->db->executeStatement('UPDATE review_setting SET enabled = false');
        $list = $this->json($this->request('GET', "/api/products/$product/reviews"));
        $this->assertSame([false, [], 0], [$list['enabled'], $list['items'], $list['summary']['count']]);
        $other = $this->productId('DEMO-CASQUETTE');
        $refused = $this->request('POST', "/api/products/$other/reviews", json_encode(['rating' => 5, 'body' => 'Avis désactivés']));
        $this->assertSame([403, 'disabled'], [$refused->getStatusCode(), json_decode($refused->getContent(), true)['reason']]);
    }

    public function testVisitorsReadButCannotWrite(): void
    {
        $product = $this->productId('DEMO-CASQUETTE');
        $this->session = [];
        $this->assertSame(200, $this->request('GET', "/api/products/$product/reviews")->getStatusCode());
        $this->assertSame(401, $this->request('GET', "/api/products/$product/reviews/eligibility")->getStatusCode());
        $this->assertSame(401, $this->request('POST', "/api/products/$product/reviews", json_encode(['rating' => 5, 'body' => 'Sans compte, impossible.']))->getStatusCode());
        $this->assertSame(401, $this->request('GET', '/api/reviews/mine')->getStatusCode());
        $this->assertSame(404, $this->request('GET', '/api/products/999999/reviews')->getStatusCode());
    }

    public function testAdministratorModeratesFromEasyAdmin(): void
    {
        $this->db->executeStatement("INSERT INTO review_setting (enabled, verified_only, moderation, min_length, updated_at) VALUES (true, false, 'manual', 0, NOW())");
        $this->loginAs(['ROLE_USER_INTERNET'], 'Paul', 'Martin');
        $product = $this->productId('DEMO-GOURDE');
        $created = $this->request('POST', "/api/products/$product/reviews", json_encode(['rating' => 3, 'body' => 'Correcte, sans plus.']));
        $this->assertSame(201, $created->getStatusCode(), $created->getContent());
        $id = json_decode($created->getContent(), true)['review']['id'];

        $this->loginAdmin();
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => 'index', 'crudControllerFqcn' => ReviewCrudController::class, 'status' => 'pending']), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $html = $this->client->getResponse()->getContent();
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), substr(strip_tags($html), 0, 600));
        $this->assertStringContainsString('Correcte, sans plus.', $html);
        $this->assertStringContainsString('★★★☆☆', $html);
        $this->assertMatchesRegularExpression('/Avis clients\s*<\/span>\s*<span class="menu-item-badge[^"]*"[^>]*>1</', $html, 'nombre d\'avis en attente dans le menu');
        $approve = $this->client->getCrawler()->filter('a[href*="approveReview"]');
        $this->assertCount(1, $approve);
        $this->client->request('GET', $approve->attr('href'), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame('approved', $this->db->fetchOne('SELECT status FROM reviews_product WHERE id = ?', [$id]));
        $row = $this->db->fetchAssociative('SELECT rating_average, rating_count FROM product WHERE id = ?', [$product]);
        $this->assertSame([3.0, 1], [(float) $row['rating_average'], (int) $row['rating_count']], 'moyenne recalculée à la publication');

        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => 'index', 'crudControllerFqcn' => \App\Controller\Admin\ReviewSettingCrudController::class]), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), substr(strip_tags((string) $this->client->getResponse()->getContent()), 0, 600));
        $this->assertStringContainsString('Avant publication', (string) $this->client->getResponse()->getContent());
    }

    private function buy(string $code): void
    {
        $variant = (int) $this->db->fetchOne('SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = ? ORDER BY v.id LIMIT 1', [$code]);
        $created = $this->request('POST', '/api/order/create', json_encode(['addressId' => $this->address(), 'carrierId' => 6, 'items' => [['productVariantId' => $variant, 'quantity' => 1]]]));
        $this->assertSame(201, $created->getStatusCode(), $created->getContent());
        // Commande payée : statut « En cours » (une commande du personnel peut naître « Incomplete »)
        $this->db->executeStatement('UPDATE "order" SET status_id = 2 WHERE id = ?', [(int) json_decode($created->getContent())->orderId]);
    }

    private function review(int $id): ReviewsProduct
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $em->clear();

        return $em->getRepository(ReviewsProduct::class)->find($id);
    }

    private function productId(string $code): int
    {
        return (int) $this->db->fetchOne('SELECT id FROM product WHERE code = ?', [$code]);
    }

    private function address(): int
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $this->session['email']]);
        $address = (new \App\Entity\Adress())->setFirstname('Test')->setLastname('Avis')->setFullname('Test Avis')->setAddress('1 rue du Test')->setCity('Montréal')
            ->setCodepostal('H1A 1A1')->setCountry('CA')->setPhone('+1 514 555 0000')->setProvince('QC')->setUserAdress($user);
        $em->persist($address);
        $em->flush();

        return (int) $address->getId();
    }

    private function json(Response $response): array
    {
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent(), true);
    }

    private function request(string $method, string $path, ?string $body = null): Response
    {
        $jar = $this->client->getCookieJar();
        $jar->clear();
        foreach ($this->session as $name => $value) {
            if ($name !== 'email') {
                $jar->set(new \Symfony\Component\BrowserKit\Cookie($name, $value, null, '/', MV_TEST_TENANT_HOST, true));
            }
        }
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $this->session['XSRF-TOKEN_' . MV_TEST_TENANT_CODE] ?? null,
        ]), $body);

        return $this->client->getResponse();
    }

    private function loginAs(array $roles, string $firstname, string $lastname): void
    {
        $email = 'avis-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname($firstname)->setLastname($lastname)->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'web']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->session = ['email' => $email];
        foreach ($this->client->getCookieJar()->all() as $cookie) {
            $this->session[$cookie->getName()] = $cookie->getValue();
        }
    }

    /** Administrateur connecté par la session EasyAdmin (pare-feu main) */
    private function loginAdmin(): void
    {
        $email = 'avis-admin-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Admin')->setLastname('Avis')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('x');
        $em->persist($user);
        $em->flush();
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user, 'main');
    }
}
