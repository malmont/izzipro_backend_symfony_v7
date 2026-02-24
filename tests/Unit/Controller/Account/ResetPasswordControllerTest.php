<?php

namespace App\Tests\Unit\Controller\Account;

use App\Controller\Account\ResetPasswordController;
use App\Entity\EmailConfiguration;
use App\Entity\EmailConfigurationTranslation;
use App\Entity\User;
use App\Services\EmailConfigurationService\EmailConfigurationService;
use App\Services\EmailConfigurationService\EmailLogoHelper;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

class ResetPasswordControllerTest extends TestCase
{
    private $tenantEmProvider;
    private $tenantManager;
    private $emailConfigService;
    private $emailLogoHelper;
    private $logger;
    private $container;
    private $controller;
    private $entityManager;
    private $connection;

    protected function setUp(): void
    {
        $this->tenantEmProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->tenantManager = $this->createMock(TenantConnectionManager::class);
        $this->emailConfigService = $this->createMock(EmailConfigurationService::class);
        $this->emailLogoHelper = $this->createMock(EmailLogoHelper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->connection = $this->createMock(Connection::class);

        // Setup EM and Connection mocks
        $this->tenantEmProvider->method('getEntityManager')->willReturn($this->entityManager);
        $this->entityManager->method('getConnection')->willReturn($this->connection);

        $this->controller = new ResetPasswordController(
            $this->tenantEmProvider,
            $this->tenantManager,
            $this->emailConfigService,
            $this->logger,
            $this->emailLogoHelper
        );

        // Mock Container for AbstractController helpers
        $this->container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $this->controller->setContainer($this->container);

        // Default container to prevent errors on 'serializer' check if used
        $this->container->method('has')->will($this->returnValueMap([
            ['twig', true],
            ['serializer', false]
        ]));
    }

    public function testRequestPasswordResetSuccess(): void
    {
        // 1. Request
        $requestData = ['email' => 'test@example.com'];
        $request = new Request([], [], [], [], [], ['HTTP_HOST' => 'locahost'], json_encode($requestData));

        // 2. Mocks
        $mailer = $this->createMock(MailerInterface::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);

        $userRepo = $this->createMock(EntityRepository::class);
        $this->entityManager->method('getRepository')->with(User::class)->willReturn($userRepo);

        $user = new User();
        $user->setEmail('test@example.com');
        $userRepo->expects($this->once())->method('findOneBy')->with(['email' => 'test@example.com'])->willReturn($user);

        // Email Config
        $emailConfig = $this->createMock(EmailConfiguration::class);
        $emailConfigTranslation = $this->createMock(EmailConfigurationTranslation::class);
        $this->emailConfigService->method('findOneByLocale')->willReturn($emailConfig);
        $emailConfig->method('getTranslation')->willReturn($emailConfigTranslation);
        $emailConfig->method('getFromEmail')->willReturn('noreply@test.com');
        $emailConfigTranslation->method('getFromName')->willReturn('Test Sender');

        // Twig Mock (container get)
        $twig = $this->createMock(Environment::class);
        // Map 'twig' to our mock
        $this->container->method('get')->will($this->returnValueMap([
            ['twig', $twig]
        ]));

        $twig->expects($this->once())->method('render')->willReturn('<body>Reset Link</body>');

        // Expectations
        $this->entityManager->expects($this->once())->method('flush'); // Saving token
        $mailer->expects($this->once())->method('send');
        $urlGenerator->expects($this->once())->method('generate')->willReturn('http://reset-link');

        // Execute
        $response = $this->controller->requestPasswordReset($request, $mailer, $urlGenerator);

        // Verify
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testRequestPasswordResetUserNotFound(): void
    {
        $requestData = ['email' => 'unknown@example.com'];
        $request = new Request([], [], [], [], [], [], json_encode($requestData));
        $mailer = $this->createMock(MailerInterface::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);

        $userRepo = $this->createMock(EntityRepository::class);
        $this->entityManager->method('getRepository')->willReturn($userRepo);
        $userRepo->method('findOneBy')->willReturn(null);

        $response = $this->controller->requestPasswordReset($request, $mailer, $urlGenerator);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testResetPasswordFormValidToken(): void
    {
        $request = new Request(['token' => 'valid_token']);

        $emailConfigRepo = $this->createMock(EntityRepository::class);
        $emailConfigRepo->method('findOneBy')->willReturn(new EmailConfiguration());

        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->method('find')->willReturn(new User());

        // Map repos correctly
        $this->entityManager->method('getRepository')->will($this->returnValueMap([
            [EmailConfiguration::class, $emailConfigRepo],
            [User::class, $userRepo]
        ]));

        // Assuming the controller uses findOneBy(['resetToken' => ...]) or similar
        // Based on typical implementation. If it uses SQL, we might need to mock Connection->fetchAssociative
        // Let's assume EntityRepository for now, but check if we need to adjust based on previous controller patterns.
        // Actually, previous controller (Registration) used SQL for tokens. ResetPassword typically involves logic like User repo check or SQL.
        // Let's assume SQL for consistency if it follows RegistrationController pattern, OR User repo.
        // But wait, the outline showed `resetPasswordForm(Request $request)`.

        // Preparing SQL mock just in case, as Verification used it.
        // If the controller uses $userRepo->findOneBy(['resetToken' => $token]), this SQL mock won't hurt, but won't be used.
        // If it uses SQL, we need this.
        $this->connection->method('fetchAssociative')->willReturn(['id' => 1]);
        $userRepo->method('find')->willReturn(new User());
        $userRepo->method('findOneBy')->willReturn(new User()); // Fallback if it uses findOneBy

        // Twig for render
        $twig = $this->createMock(Environment::class);
        $this->container->method('get')->willReturnCallback(function ($id) use ($twig) {
            if ($id === 'twig') return $twig;
            return null;
        });
        $this->container->method('has')->willReturnCallback(function ($id) {
            return $id === 'twig';
        });

        $twig->expects($this->once())->method('render')->willReturn('Form HTML');

        $response = $this->controller->resetPasswordForm($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testConfirmPasswordResetSuccess(): void
    {
        $requestData = [
            'token' => 'valid_token',
            'newPassword' => 'new_pass'
        ];
        $request = new Request([], [], [], [], [], [], json_encode($requestData));

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $emailConfigRepo = $this->createMock(EntityRepository::class);
        $emailConfigRepo->method('findOneBy')->willReturn(new EmailConfiguration());

        $userRepo = $this->createMock(EntityRepository::class);

        // Map repos correctly
        $this->entityManager->method('getRepository')->will($this->returnValueMap([
            [EmailConfiguration::class, $emailConfigRepo],
            [User::class, $userRepo]
        ]));

        $user = $this->createMock(User::class);
        // Assuming findOneBy(['resetToken' => ...])
        $userRepo->method('findOneBy')->willReturn($user);

        // Fix Expiration Check: null < DateTime is true in PHP, so we must return a future date
        $futureDate = new \DateTimeImmutable('+1 hour');
        $user->method('getResetTokenExpiresAt')->willReturn($futureDate);

        $hasher->expects($this->once())->method('hashPassword')->willReturn('hashed_new_pass');
        $user->expects($this->once())->method('setPassword')->with('hashed_new_pass');
        $user->expects($this->once())->method('setResetToken')->with(null);

        $this->entityManager->expects($this->once())->method('flush');

        $response = $this->controller->confirmPasswordReset($request, $hasher);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }
}
