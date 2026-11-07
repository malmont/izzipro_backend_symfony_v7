<?php

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeLandingAiClient;
use App\Tests\Functional\Support\OtpTestHelper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Console IA flottante (07/11/2026) : injectée sur toute page EasyAdmin pour un administrateur du site, avec un jeton qui ouvre bien
 * l'assistant de « Mon site » ; absente pour un client.
 */
class AssistantConsoleTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeLandingAiClient::reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $provider->getEntityManager()->getConnection()->executeStatement('DELETE FROM ai_usage');
    }

    public function testTheConsoleFollowsTheAdminOnEveryPageAndTalksToTheAssistant(): void
    {
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        // les pages de route s'ouvrent depuis le menu en URL habillée EasyAdmin (routeName=…), c'est là que la console est injectée
        foreach (['/admin', '/admin?routeName=admin_configuration', '/admin?routeName=admin_orders_todo'] as $path) {
            $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
            $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $path);
            $this->assertSame(1, $crawler->filter('#arkanoa-assistant[data-chat][data-confirm][data-token]')->count(), "console présente sur $path");
            $this->assertStringContainsString('assets/js/admin-assistant.js', (string) $this->client->getResponse()->getContent());
        }
        $root = $crawler->filter('#arkanoa-assistant');
        $this->assertSame('full', $root->attr('data-tier'), 'mode démo de la plateforme : palier complet');
        FakeLandingAiClient::$queue[] = (object) ['content' => [(object) ['type' => 'text', 'text' => 'Bonjour depuis la console.']], 'usage' => (object) ['input_tokens' => 1, 'output_tokens' => 1]];
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . $root->attr('data-chat'), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $root->attr('data-token')], json_encode(['messages' => [['role' => 'user', 'content' => 'Bonjour (Je suis sur la page « Commandes à traiter » du back-office.)']]]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->assertSame('Bonjour depuis la console.', json_decode((string) $this->client->getResponse()->getContent(), true)['reply']);
        $this->assertStringContainsString('Commandes à traiter', FakeLandingAiClient::$requests[0]['messages'][0]['content'], 'la page courante est transmise à l\'assistant');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . $root->attr('data-chat'), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'faux'], json_encode(['messages' => [['role' => 'user', 'content' => 'x']]]));
        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'jeton obligatoire');
    }

    public function testNoConsoleForACustomer(): void
    {
        $this->loginAs(['ROLE_USER_INTERNET']);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 403], 'le back-office est refusé au client : aucune page, donc aucune console');
    }

    private function loginAs(array $roles): void
    {
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $email = 'console-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Console')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'Mot-de-passe-de-test-1!'));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);
    }
}
