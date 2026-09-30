<?php

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Un jeton JWT sans tenant_code est refusé (depuis le 30/09/2026) : sinon, il serait accepté sur n'importe quel site.
 */
class JwtTenantClaimTest extends WebTestCase
{
    public function testTokenWithoutTenantCodeIsRefused(): void
    {
        $client = static::createClient();
        $user = $this->admin();
        $container = static::getContainer();

        $withTenant = $container->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $withoutTenant = $container->get('lexik_jwt_authentication.encoder')->encode([
            'username' => $user->getUserIdentifier(),
            'roles' => $user->getRoles(),
            'exp' => time() + 600,
        ]);

        foreach ([[$withTenant, 200], [$withoutTenant, 401]] as [$jwt, $expected]) {
            $client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/landingpage-ai/usage', [], [], [
                'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST,
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
            ]);
            $this->assertSame($expected, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        }
    }

    private function admin(): User
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $provider->getEntityManager();
        $email = 'jwt-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Jwt')->setLastname('Test')
            ->setRoles(['ROLE_ADMIN', 'ROLE_USER_INTERNET'])->setPassword('inutilisable')->setIsVerified(true);
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
