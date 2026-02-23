<?php

namespace App\Tests\Unit\Service;

use App\Services\TenantConnectionProvider;
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

        $tenantProvider = $this->createMock(TenantConnectionProvider::class);
        $tenantProvider->method('getTenantCode')->willReturn('default');

        // 3. EXECUTION
        $service = new TokenService($jwtManager, $emProvider, $tenantProvider);
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

        // 2. DEPENDENCIES
        $jwtManager = $this->createMock(JWTTokenManagerInterface::class);
        $emProvider = $this->createMock(TenantEntityManagerProvider::class);

        $tenantProvider = $this->createMock(TenantConnectionProvider::class);
        $tenantProvider->method('getTenantCode')->willReturn('default');

        $service = new TokenService(
            $jwtManager,
            $emProvider,
            $tenantProvider
        );

        // 3. EXECUTION
        $response = $service->createResponseWithTokens($tokens, $platform, $host);

        // 4. ASSERTIONS
        $cookies = $response->headers->getCookies();
        // Now we expect 3 cookies: auth_token_default, refresh_token_default, XSRF-TOKEN_default
        $this->assertCount(3, $cookies);

        // Vérification Cookie JWT
        $jwtCookie = null;
        foreach ($cookies as $c) {
            if ($c->getName() === 'auth_token_default') $jwtCookie = $c;
        }
        $this->assertNotNull($jwtCookie, 'JWT cookie (auth_token_default) not found');
        $this->assertEquals('jwt_val', $jwtCookie->getValue());
        $this->assertTrue($jwtCookie->isHttpOnly());
        $this->assertTrue($jwtCookie->isSecure());
        $this->assertEquals(Cookie::SAMESITE_NONE, $jwtCookie->getSameSite());

        // Vérification Cookie Refresh
        $refreshCookie = null;
        foreach ($cookies as $c) {
            if ($c->getName() === 'refresh_token_default') $refreshCookie = $c;
        }
        $this->assertNotNull($refreshCookie, 'Refresh token cookie (refresh_token_default) not found');
        $this->assertEquals('refresh_val', $refreshCookie->getValue());

        // Ensure XSRF-TOKEN_default is present
        $xsrfCookie = null;
        foreach ($cookies as $c) {
            if ($c->getName() === 'XSRF-TOKEN_default') $xsrfCookie = $c;
        }
        $this->assertNotNull($xsrfCookie, 'XSRF-TOKEN_default cookie not found');
        $this->assertFalse($xsrfCookie->isHttpOnly(), 'XSRF-TOKEN should NOT be HttpOnly');
    }

    public function testCreateResponseWithTokensDoesNotAddCookiesForMobilePlatform(): void
    {
        // 1. DATA
        $tokens = ['token' => 'jwt_val', 'refresh_token' => 'refresh_val'];
        $platform = 'mobile';
        $host = 'example.com';

        // 2. DEPENDENCIES
        $jwtManager = $this->createMock(JWTTokenManagerInterface::class);
        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $tenantProvider = $this->createMock(TenantConnectionProvider::class);

        // 3. EXECUTION
        $service = new TokenService(
            $jwtManager,
            $emProvider,
            $tenantProvider
        );

        $response = $service->createResponseWithTokens($tokens, $platform, $host);

        // 3. ASSERTIONS
        $this->assertCount(0, $response->headers->getCookies());

        $content = json_decode($response->getContent(), true);
        $this->assertEquals('jwt_val', $content['token']);
        $this->assertEquals('refresh_val', $content['refresh_token']);
    }
}
