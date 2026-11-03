<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\Adress;
use App\Entity\Subscription;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\SubscriptionService\SubscriptionException;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeSubscriptionStripeGateway;
use App\UseCase\SubscriptionUseCase\AdminSubscriptionUseCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Codes stables des refus des abonnements (04/11/2026, application e-commerce mobile) : `code` de premier niveau, même code et
 * `params` sur chaque élément d'`errors[]` ; `error`, `errors`, `reason`, `subscriptionId`, `status` et les statuts HTTP inchangés.
 * Un test par code ; corps complet vérifié pour le 403, le 409 already_subscribed et un 422.
 */
class SubscriptionErrorCodesApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';

    private KernelBrowser $client;
    private array $session = [];
    private \Doctrine\DBAL\Connection $db;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeSubscriptionStripeGateway::reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-abonnement@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->loginAs();
    }

    public function testSubscriptionsDisabledCarriesItsCodeAndNothingElseChanges(): void
    {
        $this->db->executeStatement("INSERT INTO boutique_setting (configuration) VALUES ('{\"commerce\": {\"subscriptionsEnabled\": false}}')");
        try {
            $response = $this->request('POST', '/api/subscriptions', json_encode(['planId' => $this->plan()]));
            $this->assertSame(403, $response->getStatusCode());
            $this->assertSame([
                'error' => 'Les abonnements sont désactivés sur ce site.',
                'code' => 'SUBSCRIPTIONS_DISABLED',
                'message' => 'Les abonnements sont désactivés sur ce site.',
            ], json_decode($response->getContent(), true), 'corps complet : error inchangé, code et message ajoutés');
        } finally {
            $this->db->executeStatement('DELETE FROM boutique_setting');
        }
    }

    public function testPlanNotFound(): void
    {
        $this->assertRefusal(404, 'PLAN_NOT_FOUND', 'Formule introuvable.', $this->request('POST', '/api/subscriptions', json_encode(['planId' => 999999])));
    }

    public function testPlanNotSubscribable(): void
    {
        $this->db->executeStatement("UPDATE product SET subscription_enabled = false WHERE code = 'DEMO-PANIER'");
        try {
            $body = $this->assertRefusal(422, 'PLAN_NOT_SUBSCRIBABLE', 'Ce produit ne se vend pas par abonnement.', $this->request('POST', '/api/subscriptions', json_encode(['planId' => $this->plan()])));
            $this->assertSame([['path' => 'planId', 'message' => 'produit sans abonnement', 'code' => 'PLAN_NOT_SUBSCRIBABLE']], $body['errors']);
        } finally {
            $this->db->executeStatement("UPDATE product SET subscription_enabled = true WHERE code = 'DEMO-PANIER'");
        }
    }

    public function testPlanIdRequiredWithTheFullBodyOfA422(): void
    {
        $response = $this->request('POST', '/api/subscriptions', json_encode(['planId' => 'x']));
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame([
            'error' => 'planId : identifiant de formule attendu',
            'errors' => [['path' => 'planId', 'message' => 'identifiant attendu', 'code' => 'PLAN_ID_REQUIRED']],
            'code' => 'PLAN_ID_REQUIRED',
            'message' => 'planId : identifiant de formule attendu',
        ], json_decode($response->getContent(), true), 'corps complet d\'un 422');
    }

    public function testQuantityInvalidCarriesTheBounds(): void
    {
        $plan = $this->plan();
        $body = $this->assertRefusal(422, 'QUANTITY_INVALID', 'quantity : entier attendu', $this->request('POST', '/api/subscriptions', json_encode(['planId' => $plan, 'quantity' => 'abc'])));
        $this->assertSame([['path' => 'quantity', 'message' => 'entier attendu', 'code' => 'QUANTITY_INVALID', 'params' => ['min' => 1, 'max' => 100]]], $body['errors']);
        $body = $this->assertRefusal(422, 'QUANTITY_INVALID', 'quantity : entier de 1 à 100 attendu', $this->request('POST', '/api/subscriptions', json_encode(['planId' => $plan, 'quantity' => 0])));
        $this->assertSame([['path' => 'quantity', 'message' => 'entier de 1 à 100 attendu', 'code' => 'QUANTITY_INVALID', 'params' => ['min' => 1, 'max' => 100]]], $body['errors']);
        $this->assertSame(422, $this->request('POST', '/api/subscriptions', json_encode(['planId' => $plan, 'quantity' => 101]))->getStatusCode());
    }

    public function testAddressNotFound(): void
    {
        $body = $this->assertRefusal(422, 'ADDRESS_NOT_FOUND', 'addressId : adresse introuvable', $this->request('POST', '/api/subscriptions', json_encode(['planId' => $this->plan(), 'addressId' => 999999])));
        $this->assertSame('ADDRESS_NOT_FOUND', $body['errors'][0]['code']);
    }

    public function testCarrierNotFound(): void
    {
        $body = $this->assertRefusal(422, 'CARRIER_NOT_FOUND', 'carrierId : transporteur introuvable', $this->request('POST', '/api/subscriptions', json_encode(['planId' => $this->plan(), 'carrierId' => 999999])));
        $this->assertSame([['path' => 'carrierId', 'message' => 'transporteur introuvable', 'code' => 'CARRIER_NOT_FOUND']], $body['errors']);
    }

    public function testCarrierRatesNotAllowed(): void
    {
        // Transporteur 2 du jeu d'essai : EasyPost, tarif variable
        $body = $this->assertRefusal(422, 'CARRIER_RATES_NOT_ALLOWED', 'carrierId : un abonnement exige un transporteur à prix fixe ou gratuit', $this->request('POST', '/api/subscriptions', json_encode(['planId' => $this->plan(), 'addressId' => $this->address(), 'carrierId' => 2])));
        $this->assertSame('CARRIER_RATES_NOT_ALLOWED', $body['errors'][0]['code']);
    }

    public function testAlreadySubscribedKeepsReasonSubscriptionIdAndStatus(): void
    {
        $id = $this->subscribe();
        $this->db->executeStatement("UPDATE subscription SET status = 'active' WHERE id = ?", [$id]);
        $response = $this->request('POST', '/api/subscriptions', json_encode(['planId' => $this->plan()]));
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame([
            'error' => 'Vous êtes déjà abonné à ce produit : changez de formule ou résiliez depuis votre compte.',
            'errors' => [['path' => 'planId', 'message' => "abonnement n° $id déjà en cours", 'code' => 'ALREADY_SUBSCRIBED', 'params' => ['subscriptionId' => $id, 'status' => 'active']]],
            'code' => 'ALREADY_SUBSCRIBED',
            'message' => 'Vous êtes déjà abonné à ce produit : changez de formule ou résiliez depuis votre compte.',
            'reason' => 'already_subscribed',
            'subscriptionId' => $id,
            'status' => 'active',
        ], json_decode($response->getContent(), true), 'corps complet : reason, subscriptionId et status conservés');
    }

    public function testSubscriptionNotFoundForAnotherCustomer(): void
    {
        $id = $this->subscribe();
        $this->loginAs();
        $this->assertRefusal(404, 'SUBSCRIPTION_NOT_FOUND', 'Abonnement introuvable.', $this->request('GET', "/api/subscriptions/$id"));
        $this->assertRefusal(404, 'SUBSCRIPTION_NOT_FOUND', 'Abonnement introuvable.', $this->request('POST', "/api/subscriptions/$id/cancel", '{}'));
    }

    public function testSubscriptionCanceledOnResumeAndChangePlan(): void
    {
        $id = $this->subscribe();
        $this->assertSame(200, $this->request('POST', "/api/subscriptions/$id/cancel", json_encode(['atPeriodEnd' => false]))->getStatusCode());
        $this->assertRefusal(409, 'SUBSCRIPTION_CANCELED', 'Abonnement déjà résilié : souscrivez de nouveau.', $this->request('POST', "/api/subscriptions/$id/resume"));
        $this->assertRefusal(409, 'SUBSCRIPTION_CANCELED', 'Abonnement résilié : souscrivez de nouveau.', $this->request('POST', "/api/subscriptions/$id/change-plan", json_encode(['planId' => $this->plan()])));
    }

    public function testSubscriptionNotActiveOnPause(): void
    {
        $id = $this->subscribe(); // incomplet : première facture non payée
        $this->assertRefusal(409, 'SUBSCRIPTION_NOT_ACTIVE', 'Seul un abonnement actif peut être mis en pause.', $this->request('POST', "/api/subscriptions/$id/pause"));
    }

    public function testChangePlanRefusals(): void
    {
        $id = $this->subscribe();
        $this->assertRefusal(422, 'PLAN_ID_REQUIRED', 'planId : identifiant de formule attendu', $this->request('POST', "/api/subscriptions/$id/change-plan", json_encode(['planId' => 'x'])));
        $this->assertRefusal(404, 'PLAN_NOT_FOUND', 'Formule introuvable.', $this->request('POST', "/api/subscriptions/$id/change-plan", json_encode(['planId' => 999999])));
        $other = (int) $this->db->fetchOne("INSERT INTO subscription_plan (id, product_id, names, interval, interval_count, price, currency, trial_days, minimum_terms, active, created_at, highlighted)
            VALUES (nextval('subscription_plan_id_seq'), ?, '{\"fr\": \"Autre produit\"}', 'month', 1, 1000, 'CAD', 0, 0, true, NOW(), false) RETURNING id", [$this->productId('DEMO-CASQUETTE')]);
        try {
            $body = $this->assertRefusal(422, 'PLAN_OTHER_PRODUCT', 'planId : la nouvelle formule doit concerner le même produit', $this->request('POST', "/api/subscriptions/$id/change-plan", json_encode(['planId' => $other])));
            $this->assertSame([['path' => 'planId', 'message' => 'formule d\'un autre produit', 'code' => 'PLAN_OTHER_PRODUCT']], $body['errors']);
        } finally {
            $this->db->executeStatement('DELETE FROM subscription_plan WHERE id = ?', [$other]);
        }
    }

    public function testSubscriptionNotStarted(): void
    {
        $id = $this->subscribe();
        $this->db->executeStatement('UPDATE subscription SET stripe_subscription_id = NULL WHERE id = ?', [$id]);
        $this->assertRefusal(409, 'SUBSCRIPTION_NOT_STARTED', 'Abonnement sans abonnement Stripe : souscription non terminée.', $this->request('POST', "/api/subscriptions/$id/cancel", '{}'));
    }

    public function testNothingToRefundFromTheAdministration(): void
    {
        $id = $this->subscribe(); // aucune facture payée chez Stripe (simulé)
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        try {
            static::getContainer()->get(AdminSubscriptionUseCase::class)->refundLatest($em->getRepository(Subscription::class)->find($id));
            $this->fail('remboursement sans paiement : refus attendu');
        } catch (SubscriptionException $e) {
            $this->assertSame([409, 'NOTHING_TO_REFUND', 'Aucun paiement encaissé à rembourser pour cet abonnement.'], [$e->getStatusCode(), $e->code(), $e->getMessage()]);
        }
    }

    public function testGatewayCodesAndTheFallbackByStatus(): void
    {
        // Codes posés par SubscriptionStripeGateway (Stripe réel, hors de portée du simulateur) et repli par statut sans code explicite
        $this->assertSame('STRIPE_ERROR', (new SubscriptionException(502, 'Stripe : indisponible', [], [], 'STRIPE_ERROR'))->code());
        $this->assertSame('ALREADY_REFUNDED', (new SubscriptionException(409, 'Le dernier paiement de cet abonnement est déjà remboursé.', [], [], 'ALREADY_REFUNDED'))->code());
        $this->assertSame('CONFLICT', (new SubscriptionException(409, 'sans code'))->code());
        $this->assertSame(['path' => 'x', 'message' => 'm', 'code' => 'C', 'params' => ['a' => 1]], SubscriptionException::error('x', 'C', 'm', ['a' => 1]));
    }

    /** @return array<string, mixed> corps décodé */
    private function assertRefusal(int $status, string $code, string $error, Response $response): array
    {
        $this->assertSame($status, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->assertSame([$error, $code, $error], [$body['error'] ?? null, $body['code'] ?? null, $body['message'] ?? null], $response->getContent());
        foreach ($body['errors'] ?? [] as $item) {
            $this->assertSame($code, $item['code'], 'même code sur chaque élément d\'errors[]');
        }

        return $body;
    }

    /** Souscription du client connecté (abonnement incomplet, Stripe simulé) */
    private function subscribe(): int
    {
        $created = $this->request('POST', '/api/subscriptions', json_encode(['planId' => $this->plan()]));
        $this->assertSame(201, $created->getStatusCode(), $created->getContent());

        return (int) json_decode($created->getContent(), true)['subscriptionId'];
    }

    private function plan(): int
    {
        return (int) $this->db->fetchOne('SELECT id FROM subscription_plan WHERE product_id = ? AND active = true ORDER BY id LIMIT 1', [$this->productId('DEMO-PANIER')]);
    }

    private function productId(string $code): int
    {
        return (int) $this->db->fetchOne('SELECT id FROM product WHERE code = ?', [$code]);
    }

    private function address(): int
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $this->session['email']]);
        $address = (new Adress())->setFirstname('Abo')->setLastname('Test')->setFullname('Abo Test')->setAddress('2 rue du Test')->setCity('Québec')
            ->setCodepostal('G1A 1A1')->setCountry('CA')->setPhone('+1 418 555 0000')->setProvince('QC')->setUserAdress($user);
        $em->persist($address);
        $em->flush();

        return (int) $address->getId();
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
        $email = 'codes-abo-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Abonné')->setLastname('Codes')->setRoles(['ROLE_USER_INTERNET'])->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'web']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->session = ['email' => $email];
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            $this->session[$cookie->getName()] = $cookie->getValue();
        }
    }
}
