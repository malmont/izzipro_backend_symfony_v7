<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Codes stables des refus des avis clients (B15, 04/11/2026, application mobile lot L7d) : `code` et `message` au premier niveau,
 * `errors[]` codées avec `params`, `reason` et statuts inchangés ; 429 TOO_MANY_REVIEWS avec `retryAfter`. Un cas par code.
 */
class ReviewErrorCodesApiTest extends WebTestCase
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
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-codes-avis@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM reviews_product');
        $this->db->executeStatement('DELETE FROM review_setting');
    }

    protected function tearDown(): void
    {
        $this->db->executeStatement('DELETE FROM review_setting');
        parent::tearDown();
    }

    public function testVisitorAndUnknownProductRefusals(): void
    {
        $product = $this->productId('DEMO-CASQUETTE');
        $this->session = [];
        // Visiteur : le pare-feu JWT répond avant le contrôleur, forme inchangée {code: 401, error: JWT_NOT_FOUND}
        foreach ([['GET', "/api/products/$product/reviews/eligibility", null], ['POST', "/api/products/$product/reviews", json_encode(['rating' => 5, 'body' => 'Sans compte, impossible.'])], ['GET', '/api/reviews/mine', null]] as [$method, $path, $payload]) {
            $response = $this->request($method, $path, $payload);
            $this->assertSame([401, 'JWT_NOT_FOUND'], [$response->getStatusCode(), json_decode($response->getContent(), true)['error']], "$method $path");
        }
        $this->assertRefusal(404, 'PRODUCT_NOT_FOUND', $this->request('GET', '/api/products/999999/reviews'), 'Produit introuvable.');
        $this->loginAs();
        $this->assertRefusal(404, 'PRODUCT_NOT_FOUND', $this->request('GET', '/api/products/999999/reviews/eligibility'), 'Produit introuvable.');
        $this->assertRefusal(404, 'REVIEW_NOT_FOUND', $this->request('PUT', '/api/reviews/999999', json_encode(['body' => 'Un texte assez long pour passer.'])), 'Avis introuvable.');
        $this->assertRefusal(404, 'REVIEW_NOT_FOUND', $this->request('DELETE', '/api/reviews/999999'), 'Avis introuvable.');
    }

    public function testEligibilityReasonsAreAlsoTheCodesOfTheRefusals(): void
    {
        $this->loginAs();
        $product = $this->productId('DEMO-CASQUETTE');
        // Réglages par défaut : acheteurs vérifiés seulement
        $eligibility = json_decode($this->request('GET', "/api/products/$product/reviews/eligibility")->getContent(), true);
        $this->assertSame([false, 'not_purchased'], [$eligibility['canReview'], $eligibility['reason']], 'raison en minuscules, inchangée');
        $body = $this->assertRefusal(403, 'NOT_PURCHASED', $this->request('POST', "/api/products/$product/reviews", json_encode(['rating' => 5, 'body' => 'Un texte assez long pour passer.'])), 'Seuls les clients qui ont acheté ce produit peuvent donner leur avis.');
        $this->assertSame('not_purchased', $body['reason']);

        $this->db->executeStatement("INSERT INTO review_setting (enabled, verified_only, moderation, min_length, policy, updated_at) VALUES (true, false, 'auto', 20, '{}', NOW())");
        $created = $this->request('POST', "/api/products/$product/reviews", json_encode(['rating' => 5, 'body' => 'Un texte assez long pour passer.']));
        $this->assertSame(201, $created->getStatusCode(), $created->getContent());
        $id = (int) json_decode($created->getContent(), true)['review']['id'];
        $body = $this->assertRefusal(409, 'ALREADY_REVIEWED', $this->request('POST', "/api/products/$product/reviews", json_encode(['rating' => 4, 'body' => 'Un second avis, refusé bien sûr.'])), 'Vous avez déjà donné votre avis sur ce produit : modifiez-le.');
        $this->assertSame('already_reviewed', $body['reason']);
        $this->assertSame('already_reviewed', json_decode($this->request('GET', "/api/products/$product/reviews/eligibility")->getContent(), true)['reason']);

        $this->db->executeStatement('UPDATE review_setting SET enabled = false');
        $this->assertSame('disabled', json_decode($this->request('GET', "/api/products/$product/reviews/eligibility")->getContent(), true)['reason']);
        $other = $this->productId('DEMO-GOURDE');
        $body = $this->assertRefusal(403, 'DISABLED', $this->request('POST', "/api/products/$other/reviews", json_encode(['rating' => 5, 'body' => 'Un texte assez long pour passer.'])), 'Les avis sont désactivés sur ce site.');
        $this->assertSame('disabled', $body['reason']);
        $this->assertRefusal(403, 'DISABLED', $this->request('PUT', "/api/reviews/$id", json_encode(['body' => 'Modification pendant la désactivation.'])), 'Les avis sont désactivés sur ce site.');
    }

    public function testEveryFieldErrorCarriesItsCodeAndParams(): void
    {
        $this->db->executeStatement("INSERT INTO review_setting (enabled, verified_only, moderation, min_length, policy, updated_at) VALUES (true, false, 'manual', 20, '{}', NOW())");
        $this->loginAs();
        $product = $this->productId('DEMO-CASQUETTE');
        $long = 'Un texte assez long pour passer la longueur minimale.';
        foreach ([
            ['RATING_INVALID', ['min' => 1, 'max' => 5], 'rating : entier de 1 à 5 attendu', ['rating' => 7, 'body' => $long]],
            ['RATING_INVALID', ['min' => 1, 'max' => 5], 'rating : entier de 1 à 5 attendu', ['body' => $long]],
            ['TITLE_INVALID', null, 'title : texte attendu', ['rating' => 5, 'title' => ['x'], 'body' => $long]],
            ['TITLE_TOO_LONG', ['max' => 120], 'title : 120 caractères au plus', ['rating' => 5, 'title' => str_repeat('t', 121), 'body' => $long]],
            ['BODY_INVALID', null, 'body : texte attendu', ['rating' => 5, 'body' => 12]],
            ['BODY_TOO_LONG', ['max' => 2000], 'body : 2000 caractères au plus', ['rating' => 5, 'body' => str_repeat('b', 2001)]],
            ['BODY_TOO_SHORT', ['min' => 20], 'body : 20 caractères au moins', ['rating' => 5, 'body' => 'Trop court']],
        ] as $i => [$code, $params, $error, $payload]) {
            if ($i % 4 === 0) {
                $this->loginAs(); // review_submit : 5 envois par heure et par compte, refus compris
            }
            $body = $this->assertRefusal(422, $code, $this->request('POST', "/api/products/$product/reviews", json_encode($payload)), $error);
            $this->assertSame($code, $body['errors'][0]['code'], $code);
            $this->assertSame($params, $body['errors'][0]['params'] ?? null, "$code params");
            $this->assertArrayHasKey('path', $body['errors'][0]);
            $this->assertArrayHasKey('message', $body['errors'][0]);
        }
        // Plusieurs champs fautifs : le code de premier niveau est celui de la première erreur, chaque erreur garde le sien
        $this->loginAs();
        $body = $this->assertRefusal(422, 'RATING_INVALID', $this->request('POST', "/api/products/$product/reviews", json_encode(['rating' => 0, 'body' => 3])), 'rating : entier de 1 à 5 attendu');
        $this->assertSame(['RATING_INVALID', 'BODY_INVALID'], array_column($body['errors'], 'code'));
    }

    public function testTooManyReviewsCarriesItsCodeAndRetryAfter(): void
    {
        $this->db->executeStatement("INSERT INTO review_setting (enabled, verified_only, moderation, min_length, policy, updated_at) VALUES (true, false, 'manual', 20, '{}', NOW())");
        $this->loginAs();
        $product = $this->productId('DEMO-CASQUETTE');
        $response = null;
        for ($i = 0; $i < 6; $i++) { // review_submit : 5 par heure et par compte ; les refus comptent aussi
            $response = $this->request('POST', "/api/products/$product/reviews", json_encode(['rating' => 5, 'body' => 'Trop court']));
        }
        $body = $this->assertRefusal(429, 'TOO_MANY_REVIEWS', $response, 'Trop d\'avis envoyés : réessayez plus tard.');
        $this->assertGreaterThan(0, $body['retryAfter']);
        $this->assertLessThanOrEqual(3600, $body['retryAfter']);
    }

    /** @return array<string, mixed> corps décodé */
    private function assertRefusal(int $status, string $code, Response $response, string $error): array
    {
        $body = json_decode($response->getContent(), true);
        $this->assertSame($status, $response->getStatusCode(), $response->getContent());
        $this->assertSame($code, $body['code'] ?? null, $response->getContent());
        $this->assertArrayHasKey('message', $body);
        $this->assertSame($error, $body['error'], 'message français inchangé');

        return $body;
    }

    private function productId(string $code): int
    {
        return (int) $this->db->fetchOne('SELECT id FROM product WHERE code = ?', [$code]);
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

    private function loginAs(): void
    {
        $email = 'codes-avis-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Marie')->setLastname('Tremblay')->setRoles(['ROLE_USER_INTERNET'])->setIsVerified(true);
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
}
