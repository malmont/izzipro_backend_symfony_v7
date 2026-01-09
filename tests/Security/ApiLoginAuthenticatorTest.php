<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\ApiLoginAuthenticator;
use App\Security\CustomAuthenticationSuccessHandler;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;

class ApiLoginAuthenticatorTest extends TestCase
{
    private $urlGenerator;
    private $tenantEmProvider;
    private $successHandler;
    private $authenticator;
    private $entityManager;
    private $userRepository;

    protected function setUp(): void
    {
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->tenantEmProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->successHandler = $this->createMock(CustomAuthenticationSuccessHandler::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->userRepository = $this->createMock(UserRepository::class);

        $this->tenantEmProvider->method('getEntityManager')->willReturn($this->entityManager);
        $this->entityManager->method('getRepository')->with(User::class)->willReturn($this->userRepository);

        $this->authenticator = new ApiLoginAuthenticator(
            $this->urlGenerator,
            $this->tenantEmProvider,
            $this->successHandler
        );
    }

    private function createRequest(array $data): Request
    {
        $request = new Request([], [], [], [], [], [], json_encode($data));
        return $request;
    }

    public function testAuthenticateSuccessMobile(): void
    {
        $email = 'test@example.com';
        $password = 'password';
        $request = $this->createRequest(['username' => $email, 'password' => $password, 'platform' => 'mobile']);

        $user = $this->createMock(User::class);
        $user->method('isVerified')->willReturn(true);
        $user->method('getRoles')->willReturn(['ROLE_USER_INTERNET']);

        $this->userRepository->expects($this->once())
            ->method('findOneBy')
            ->with(['email' => $email])
            ->willReturn($user);

        $passport = $this->authenticator->authenticate($request);

        $this->assertInstanceOf(UserBadge::class, $passport->getBadge(UserBadge::class));
        $this->assertInstanceOf(PasswordCredentials::class, $passport->getBadge(PasswordCredentials::class));

        // Verify user loading callback
        $userBadge = $passport->getBadge(UserBadge::class);
        $loader = $userBadge->getUserLoader();
        $loadedUser = $loader($email);
        $this->assertSame($user, $loadedUser);
    }

    public function testAuthenticateSuccessWeb(): void
    {
        $email = 'test@example.com';
        $password = 'password';
        $request = $this->createRequest(['username' => $email, 'password' => $password, 'platform' => 'web']);

        $user = $this->createMock(User::class);
        $user->method('isVerified')->willReturn(true);
        $user->method('getRoles')->willReturn(['ROLE_USER_INTERNET']);

        $this->userRepository->expects($this->once())
            ->method('findOneBy')
            ->with(['email' => $email])
            ->willReturn($user);

        $passport = $this->authenticator->authenticate($request);
        $userBadge = $passport->getBadge(UserBadge::class);
        $loader = $userBadge->getUserLoader();
        $this->assertSame($user, $loader($email));
    }

    public function testAuthenticateSuccessPos(): void
    {
        $email = 'test@example.com';
        $password = 'password';
        $request = $this->createRequest(['username' => $email, 'password' => $password, 'platform' => 'pos']);

        $user = $this->createMock(User::class);
        $user->method('isVerified')->willReturn(true);
        $user->method('getRoles')->willReturn(['ROLE_USER_POS']);

        $this->userRepository->expects($this->once())
            ->method('findOneBy')
            ->with(['email' => $email])
            ->willReturn($user);

        $passport = $this->authenticator->authenticate($request);
        $userBadge = $passport->getBadge(UserBadge::class);
        $loader = $userBadge->getUserLoader();
        $this->assertSame($user, $loader($email));
    }

    public function testAuthenticateUserNotFound(): void
    {
        $email = 'unknown@example.com';
        $request = $this->createRequest(['username' => $email, 'password' => 'pass']);

        $this->userRepository->expects($this->once())
            ->method('findOneBy')
            ->with(['email' => $email])
            ->willReturn(null);

        $passport = $this->authenticator->authenticate($request);
        $userBadge = $passport->getBadge(UserBadge::class);
        $loader = $userBadge->getUserLoader();

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('User not found.');
        $loader($email);
    }

    public function testAuthenticateUserNotVerified(): void
    {
        $email = 'test@example.com';
        $request = $this->createRequest(['username' => $email, 'password' => 'pass']);

        $user = $this->createMock(User::class);
        $user->method('isVerified')->willReturn(false);

        $this->userRepository->expects($this->once())
            ->method('findOneBy')
            ->with(['email' => $email])
            ->willReturn($user);

        $passport = $this->authenticator->authenticate($request);
        $userBadge = $passport->getBadge(UserBadge::class);
        $loader = $userBadge->getUserLoader();

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Your account is not verified. Please check your email.');
        $loader($email);
    }

    public function testAuthenticateInvalidRoleForPlatform(): void
    {
        $email = 'test@example.com';
        $request = $this->createRequest(['username' => $email, 'password' => 'pass', 'platform' => 'web']);

        $user = $this->createMock(User::class);
        $user->method('isVerified')->willReturn(true);
        $user->method('getRoles')->willReturn(['ROLE_SomethingElse']);

        $this->userRepository->expects($this->once())
            ->method('findOneBy')
            ->with(['email' => $email])
            ->willReturn($user);

        $passport = $this->authenticator->authenticate($request);
        $userBadge = $passport->getBadge(UserBadge::class);
        $loader = $userBadge->getUserLoader();

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('This account is not allowed to access the web/mobile platform.');
        $loader($email);
    }

    public function testOnAuthenticationSuccess(): void
    {
        $request = $this->createMock(Request::class);
        $token = $this->createMock(TokenInterface::class);
        $response = $this->createMock(Response::class);

        $this->successHandler->expects($this->once())
            ->method('onAuthenticationSuccess')
            ->with($request, $token)
            ->willReturn($response);

        $result = $this->authenticator->onAuthenticationSuccess($request, $token, 'firewall');

        $this->assertSame($response, $result);
    }
}
