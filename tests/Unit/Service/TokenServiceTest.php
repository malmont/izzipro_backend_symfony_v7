<?php

namespace App\Tests\Unit\Service;

use App\Services\TenantEntityManagerProvider;
use App\Services\TokenService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Security\Core\User\UserInterface;

class TokenServiceTest extends TestCase
{
    public function testGenerateTokensCreatesJwtAndPersistsRefreshToken(): void
    {
        // 1. DATA
        $username = 'user@test.com';

        // 2. MOCKS
        $user = $this->createMock(UserInterface::class);
        $user->method('getUserIdentifier')->willReturn($username);

        $jwtManager = $this->createMock(JWTTokenManagerInterface::class);
        $jwtManager->expects($this->once())
            ->method('create')
            ->with($user)
            ->willReturn('fake_jwt_token');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(RefreshToken::class));
        $em->expects($this->once())->method('flush');

        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $emProvider->method('getEntityManager')->willReturn($em);

        // 3. EXECUTION
        $service = new TokenService($jwtManager, $emProvider);
        $result = $service->generateTokens($user);

        // 4. ASSERTIONS
        $this->assertArrayHasKey('token', $result);
        $this->assertEquals('fake_jwt_token', $result['token']);
        $this->assertArrayHasKey('refresh_token', $result);
        $this->assertNotEmpty($result['refresh_token']);
    }

    public function testCreateResponseWithTokensAddsCookiesForWebPlatform(): void
    {
        // 1. DATA
        $tokens = ['token' => 'jwt_val', 'refresh_token' => 'refresh_val'];
        $platform = 'web';
        $host = 'example.com';

        // 2. DEPENDENCIES (Not used in this method but required by constructor)
        $service = new TokenService(
            $this->createMock(JWTTokenManagerInterface::class),
            $this->createMock(TenantEntityManagerProvider::class)
        );

        // 3. EXECUTION
        $response = $service->createResponseWithTokens($tokens, $platform, $host);

        // 4. ASSERTIONS
        $cookies = $response->headers->getCookies();
        $this->assertCount(2, $cookies);

        // Vérification Cookie JWT
        $jwtCookie = null;
        foreach ($cookies as $c) {
            if ($c->getName() === 'jwt') $jwtCookie = $c;
        }
        $this->assertNotNull($jwtCookie);
        $this->assertEquals('jwt_val', $jwtCookie->getValue());
        $this->assertTrue($jwtCookie->isHttpOnly());
        $this->assertTrue($jwtCookie->isSecure());
        $this->assertEquals(Cookie::SAMESITE_NONE, $jwtCookie->getSameSite());

        // Vérification Cookie Refresh
        $refreshCookie = null;
        foreach ($cookies as $c) {
            if ($c->getName() === 'refresh_token') $refreshCookie = $c;
        }
        $this->assertNotNull($refreshCookie);
        $this->assertEquals('refresh_val', $refreshCookie->getValue());
    }

    public function testCreateResponseWithTokensDoesNotAddCookiesForMobilePlatform(): void
    {
        // 1. DATA
        $tokens = ['token' => 'jwt_val', 'refresh_token' => 'refresh_val'];
        $platform = 'mobile';
        $host = 'example.com';

        // 2. EXECUTION
        $service = new TokenService(
            $this->createMock(JWTTokenManagerInterface::class),
            $this->createMock(TenantEntityManagerProvider::class)
        );

        $response = $service->createResponseWithTokens($tokens, $platform, $host);

        // 3. ASSERTIONS
        $this->assertCount(0, $response->headers->getCookies());

        $content = json_decode($response->getContent(), true);
        $this->assertEquals('jwt_val', $content['token']);
        $this->assertEquals('refresh_val', $content['refresh_token']);
    }
}
