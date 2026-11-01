<?php

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * POST /api/register (01/11/2026, lot « Compte » de l'application e-commerce mobile) : « platform: pos » refusé (la route
 * publique créait un compte de caisse), mot de passe de 8 caractères au moins, « app: mobile » tracé comme origine.
 */
class RegisterRulesApiTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testThePublicRegistrationNeverCreatesACashRegisterAccount(): void
    {
        $email = 'caisse-' . bin2hex(random_bytes(3)) . '@example.com';
        [$status, $body] = $this->register($email, ['platform' => 'pos']);
        $this->assertSame([400, 'PLATFORM_INVALID'], [$status, $body['error']]);
        $this->assertNull($this->em()->getRepository(User::class)->findOneBy(['email' => $email]), 'aucun compte créé');

        [$status, $body] = $this->register($email, ['platform' => 'POS ']);
        $this->assertSame(400, $status, 'casse et espaces indifférents');
    }

    public function testTheRegistrationPasswordHasAtLeastEightCharacters(): void
    {
        $email = 'court-' . bin2hex(random_bytes(3)) . '@example.com';
        [$status, $body] = $this->register($email, ['password' => 'abc1234']);
        $this->assertSame([400, 'PASSWORD_TOO_SHORT'], [$status, $body['error']]);
        [$status, $body] = $this->register($email, ['password' => '']);
        $this->assertSame([400, 'PASSWORD_TOO_SHORT'], [$status, $body['error']], 'chaîne vide refusée (passait avant)');
        $this->assertNull($this->em()->getRepository(User::class)->findOneBy(['email' => $email]));

        [$status] = $this->register($email, ['password' => 'abc12345']);
        $this->assertSame(201, $status, '8 caractères acceptés');
    }

    public function testAnAccountCreatedFromTheShopAppIsTracedAsSuch(): void
    {
        $email = 'appli-' . bin2hex(random_bytes(3)) . '@example.com';
        [$status] = $this->register($email, ['app' => 'mobile']);
        $this->assertSame(201, $status);
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => $email]);
        $this->assertSame([User::SIGNUP_MOBILE_SHOP, ['ROLE_USER_INTERNET', 'ROLE_USER']], [$user->getSignupChannel(), $user->getRoles()]);

        $web = 'web-' . bin2hex(random_bytes(3)) . '@example.com';
        $this->register($web, []);
        $this->assertSame(User::SIGNUP_WEB_REGISTER, $this->em()->getRepository(User::class)->findOneBy(['email' => $web])->getSignupChannel(), 'sans app : inscription web, inchangé');
    }

    /** @return array{0: int, 1: array} */
    private function register(string $email, array $extra): array
    {
        // Adresse source propre : /api/register est limité par IP et toute la suite partage l'adresse de test
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/register', [], [], [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.78.' . random_int(1, 254) . '.' . random_int(1, 254),
        ], json_encode($extra + ['email' => $email, 'password' => 'Mot-de-passe-1!', 'firstName' => 'Jeanne', 'lastName' => 'Dupont']));
        $response = $this->client->getResponse();

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true) ?? []];
    }

    private function em(): \Doctrine\ORM\EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }
}
