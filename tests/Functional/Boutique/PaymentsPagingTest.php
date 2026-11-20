<?php

namespace App\Tests\Functional\Boutique;

use App\Dto\CandidatureInputDto;
use App\Entity\Adress;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeCheckoutPaymentGateway;
use App\UseCase\PaymentUseCase\GetPaymentsByOrderSourceUseCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Lot CMD-WEB-1 (20/11/2026) :
 * - `GET /api/payments` par page (`?page=&limit=`, comme PERF-PAGINATION) ; sans `page`, la réponse d'avant ; `orderSource=all` pour
 *   l'administrateur seulement, et seulement par page ;
 * - `?locale=fr|en` choisit la langue des moyens et des états de paiement (demande du chef) ; sans lui, la langue de la requête, comme avant ;
 * - `lienCv` d'une candidature : une adresse http ou https de 255 caractères au plus.
 */
class PaymentsPagingTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private array $session = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-desk@example.invalid', false, false);
        FakeCheckoutPaymentGateway::reset();
    }

    public function testPaymentsArePagedAndTheWholeListIsUnchanged(): void
    {
        $this->paidOrder();
        $this->paidOrder();
        $admin = $this->token(['ROLE_ADMIN']);
        $whole = $this->get('/api/payments?orderSource=1', $admin);
        $expected = array_map(fn ($dto) => $dto->toArray(), static::getContainer()->get(GetPaymentsByOrderSourceUseCase::class)->execute(1, 'https://' . MV_TEST_TENANT_HOST, 'en'));
        $this->assertSame(json_decode((string) json_encode($expected), true), $whole, 'sans page : la liste entière, comme avant');
        $this->assertSame((string) count($whole), $this->client->getResponse()->headers->get('X-Total-Count'));
        $this->assertGreaterThanOrEqual(2, count($whole));

        $first = $this->get('/api/payments?orderSource=1&page=1&limit=1', $admin);
        $this->assertSame([(string) count($whole), '1', '1'], [$this->client->getResponse()->headers->get('X-Total-Count'), $this->client->getResponse()->headers->get('X-Page'), $this->client->getResponse()->headers->get('X-Limit')]);
        $second = $this->get('/api/payments?orderSource=1&page=2&limit=1', $admin);
        $this->assertCount(1, $first);
        $this->assertNotSame($first[0]['id'], $second[0]['id']);
        $this->assertGreaterThanOrEqual($second[0]['paymentDate'], $first[0]['paymentDate'], 'du plus récent au plus ancien');
        $ids = array_column($this->get('/api/payments?orderSource=1&page=1&limit=200', $admin), 'id');
        sort($ids);
        $all = array_column($whole, 'id');
        sort($all);
        $this->assertSame($all, $ids, 'les pages couvrent la même liste');

        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/payments?orderSource=all', [], [], $this->headers($admin));
        $this->assertSame([400, 'PAGE_REQUIRED'], [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true)['code'] ?? null]);
        $this->get('/api/payments?orderSource=all&page=1&limit=10', $admin);
        $this->assertGreaterThanOrEqual(count($whole), (int) $this->client->getResponse()->headers->get('X-Total-Count'), 'toutes les sources');

        $cashier = $this->token(['ROLE_USER_POS']);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/payments?orderSource=all&page=1', [], [], $this->headers($cashier));
        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'toutes les sources : jamais pour un caissier');
        $this->get('/api/payments?orderSource=2&page=1', $cashier);
    }

    public function testTheLocaleParameterChoosesTheLanguageOfMethodsAndStatuses(): void
    {
        $this->paidOrder();
        $admin = $this->token(['ROLE_ADMIN']);
        $fr = $this->get('/api/payments?orderSource=1&locale=fr', $admin);
        $en = $this->get('/api/payments?orderSource=1&locale=en', $admin);
        $none = $this->get('/api/payments?orderSource=1', $admin);
        $this->assertSame($en, $none, 'sans le paramètre : la réponse d\'avant (langue de la requête)');
        $this->assertContains('Complété', array_column($fr, 'paymentStatus'));
        $this->assertContains('Completed', array_column($en, 'paymentStatus'));
        $this->assertNotContains('Completed', array_column($fr, 'paymentStatus'));
        $this->assertSame(array_column($en, 'id'), array_column($fr, 'id'));
        $paged = array_column($this->get('/api/payments?orderSource=1&locale=fr&page=1&limit=200', $admin), 'paymentStatus', 'id');
        $whole = array_column($fr, 'paymentStatus', 'id');
        ksort($paged);
        ksort($whole);
        $this->assertSame($whole, $paged, 'par page aussi (même langue, ligne par ligne : la liste entière n\'a pas d\'ordre)');
        $this->assertSame($none, $this->get('/api/payments?orderSource=1&locale=de', $admin), 'une langue inconnue est ignorée');
    }

    public function testTheCvLinkOfAnApplicationIsAnHttpAddress(): void
    {
        $validator = static::getContainer()->get(ValidatorInterface::class);
        $violations = function (?string $link) use ($validator): array {
            $dto = new CandidatureInputDto();
            $dto->nomComplet = 'Ana Lima';
            $dto->courriel = 'ana@example.invalid';
            $dto->emploiId = 1;
            $dto->lienCv = $link;

            return array_map(fn ($v) => $v->getPropertyPath(), iterator_to_array($validator->validate($dto)));
        };
        foreach ([null, '', 'https://exemple.org/cv.pdf', 'http://exemple.org/cv'] as $ok) {
            $this->assertSame([], $violations($ok), (string) $ok);
        }
        foreach (['javascript:alert(1)', 'ftp://exemple.org/cv.pdf', 'data:text/html,cv', 'pas une adresse', 'https://exemple.org/' . str_repeat('a', 236)] as $bad) {
            $this->assertContains('lienCv', $violations($bad), $bad);
        }
        $this->assertSame(255, strlen('https://exemple.org/' . str_repeat('a', 235)));
        $this->assertSame([], $violations('https://exemple.org/' . str_repeat('a', 235)), '255 caractères : permis');
    }

    /** @return list<array<string, mixed>> */
    private function get(string $path, string $token): array
    {
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $this->headers($token));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $path . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 300));

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function headers(string $token): array
    {
        return ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    private function token(array $roles): string
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $email = 'cmdweb1-pay-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Essai')->setLastname('Paiements')->setRoles($roles)->setIsVerified(true)->setPassword('x');
        $em->persist($user);
        $em->flush();

        return static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }

    private function paidOrder(): void
    {
        $this->loginAs(['ROLE_USER_INTERNET']);
        $variant = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $order = ['addressId' => $this->address(), 'carrierId' => 6, 'items' => [['productVariantId' => $variant, 'quantity' => 1]]];
        $intent = $this->request('POST', '/api/stripe/create-intent', json_encode(['items' => $order['items'], 'carrierId' => 6, 'order' => $order]));
        $this->assertSame(200, $intent->getStatusCode(), $intent->getContent());
        $pi = json_decode((string) $intent->getContent(), true)['paymentIntentId'];
        FakeCheckoutPaymentGateway::confirm($pi);
        $created = $this->request('POST', '/api/order/create', json_encode($order + ['paymentIntentId' => $pi]));
        $this->assertSame(201, $created->getStatusCode(), $created->getContent());
        $this->client->getCookieJar()->clear();
    }

    private function address(): int
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $this->session['email']]);
        $address = (new Adress())->setFirstname('Test')->setLastname('Paie')->setFullname('Test Paie')->setAddress('2 rue du Test')->setCity('Montréal')
            ->setCodepostal('H1A 1A1')->setCountry('CA')->setPhone('+1 514 555 0000')->setProvince('QC')->setUserAdress($user);
        $em->persist($address);
        $em->flush();

        return (int) $address->getId();
    }

    private function request(string $method, string $path, ?string $body = null): Response
    {
        $this->client->getCookieJar()->clear();
        foreach ($this->session as $name => $value) {
            if ($name !== 'email') {
                $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie($name, $value, null, '/', MV_TEST_TENANT_HOST, true));
            }
        }
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $this->session['XSRF-TOKEN_' . MV_TEST_TENANT_CODE] ?? null,
        ]), $body);

        return $this->client->getResponse();
    }

    private function loginAs(array $roles): void
    {
        $email = 'cmdweb1-payeur-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Client')->setLastname('Paie')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json'], json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'web']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->session = ['email' => $email];
        foreach ($this->client->getCookieJar()->all() as $cookie) {
            $this->session[$cookie->getName()] = $cookie->getValue();
        }
    }
}
