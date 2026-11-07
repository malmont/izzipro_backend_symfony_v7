<?php

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Services\Help\HelpCatalogService;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Support\OtpTestHelper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Aide intégrée (07/11/2026) : catalogue lu depuis docs/aide, pages reliées aux étapes et aux écrans, API et page du back-office, anonyme repoussé. */
class HelpTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        static::getContainer()->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
    }

    public function testCatalogueApiAndPage(): void
    {
        $help = static::getContainer()->get(HelpCatalogService::class);
        $this->assertGreaterThanOrEqual(10, count($help->all()));
        $this->assertSame('entreprise', $help->forStep('site.legal'));
        $this->assertSame('livraison', $help->forStep('boutique.carrier'));
        $this->assertSame('ia', $help->forSetting('ai.help'));
        $this->assertSame('caisse', $help->forScreen('pos'));
        $page = $help->get('livraison');
        $this->assertStringContainsString('<h2>', $page['html']);
        $this->assertStringContainsString('<strong>', $page['html']);
        $this->assertStringContainsString('<ul>', $page['html']);
        $this->assertNull($help->get('inconnue'));
        $this->assertNotSame([], $help->search('TVQ'));
        $this->assertSame([], $help->search('zzzzmotinexistant'));

        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/site/help', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json']);
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $token = $this->token();
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/site/help?q=taxes', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertContains('configuration', array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['pages'], 'key'));
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/site/help/commandes', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame('orders', json_decode((string) $this->client->getResponse()->getContent(), true)['screen']);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/site/help/inconnue', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?routeName=admin_aide_page&routeParams%5Bkey%5D=caisse', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame('caisse', $crawler->filter('[data-aide-page]')->attr('data-aide-page'));
        $this->assertStringContainsString('Ouvrir l\'écran concerné', html_entity_decode((string) $this->client->getResponse()->getContent()));
        $this->assertGreaterThanOrEqual(10, $crawler->filter('[data-aide-list] li')->count());
    }

    private function token(): string
    {
        $container = static::getContainer();
        $user = $container->get(TenantEntityManagerProvider::class)->getEntityManager()->getRepository(User::class)->findOneBy(['email' => 'admin@arkanoa-media.com']);
        $this->assertNotNull($user);

        return $container->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }

    private function loginAs(array $roles): void
    {
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $email = 'aide-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Aide')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'Mot-de-passe-de-test-1!'));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);
    }
}
