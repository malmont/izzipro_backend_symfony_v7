<?php

namespace App\Tests\Unit\Controller\Account;

use App\Controller\Account\RegistrationController;
use App\Entity\EmailConfiguration;
use App\Entity\EmailConfigurationTranslation;
use App\Entity\GemsuiteClient;
use App\Entity\User;
use App\Security\EmailVerifier;
use App\Services\EmailConfigurationService\EmailConfigurationService;
use App\Services\EmailConfigurationService\EmailLogoHelper;
use App\Services\GemsuiteImporterService\GemsuiteClientManager;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Twig\Environment;
use App\Services\EmailConfigurationService\EmailSenderService;

class RegistrationControllerTest extends TestCase
{
    private $emailVerifier;
    private $tenantEmProvider;
    private $client;
    private $tenantManager;
    private $logger;
    private $gemsuiteClientManager;
    private $emailConfigurationService;
    private $emailLogoHelper;
    private $container;
    private $controller;
    private $entityManager;
    private $emailSenderService;
    protected function setUp(): void
    {
        $this->emailVerifier = $this->createMock(EmailVerifier::class);
        $this->tenantEmProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->client = $this->createMock(HttpClientInterface::class);
        $this->tenantManager = $this->createMock(TenantConnectionManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->gemsuiteClientManager = $this->createMock(GemsuiteClientManager::class);
        $this->emailConfigurationService = $this->createMock(EmailConfigurationService::class);
        $this->emailLogoHelper = $this->createMock(EmailLogoHelper::class);
        $this->entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);

        $this->emailSenderService = $this->createMock(EmailSenderService::class);

        // Setup EM provider
        $this->tenantEmProvider->method('getEntityManager')->willReturn($this->entityManager);

        $this->controller = new RegistrationController(
            $this->emailVerifier,
            $this->tenantEmProvider,
            $this->client,
            $this->tenantManager,
            $this->logger,
            $this->gemsuiteClientManager,
            $this->emailSenderService,
            $this->emailLogoHelper
        );

        // Mock Container for AbstractController helpers
        $this->container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $this->controller->setContainer($this->container);

        // Default container behavior: do not configure 'has' here to avoid conflicts.
        // Unconfigured mock returns false/null which is fine for 'serializer' check.
    }

    public function testRegisterApiSuccess(): void
    {
        // 1. Request Setup
        $requestData = [
            'email' => 'newuser@example.com',
            'password' => 'secret123',
            'firstName' => 'John',
            'lastName' => 'Doe',
            'platform' => 'mobile'
        ];
        $request = new Request([], [], [], [], [], [], json_encode($requestData));

        // 2. Mocks
        $userPasswordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $userPasswordHasher->method('hashPassword')->willReturn('hashed_secret');

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://example.com/verify');

        // Repo Mocks
        $userRepo = $this->createMock(EntityRepository::class);
        $this->entityManager->method('getRepository')->with(User::class)->willReturn($userRepo);

        // No existing user
        $userRepo->expects($this->once())->method('findOneBy')->willReturn(null);

        // Tenant Manager
        $this->tenantManager->method('getCurrentTenantCode')->willReturn('TENANT_1');

        // Gemsuite Manager
        $gemsuiteClient = $this->createMock(GemsuiteClient::class);
        $this->gemsuiteClientManager->expects($this->once())
            ->method('findOrCreateClient')
            ->willReturn($gemsuiteClient);

        // Persistence
        $this->entityManager->expects($this->once())->method('persist')->with($this->isInstanceOf(User::class));
        $this->entityManager->expects($this->once())->method('flush');

        // Email Config
        $emailConfig = $this->createMock(EmailConfiguration::class);
        $emailConfigTranslation = $this->createMock(EmailConfigurationTranslation::class);

        $this->emailConfigurationService->method('findOneByLocale')->willReturn($emailConfig);
        $emailConfig->method('getTranslation')->willReturn($emailConfigTranslation);

        $emailConfig->method('getFromEmail')->willReturn('contact@shop.com');
        $emailConfigTranslation->method('getFromName')->willReturn('Shop Contact');

        // Mock Twig for renderView
        $twig = $this->createMock(Environment::class);
        $this->container->method('get')->willReturnCallback(function ($id) use ($twig) {
            if ($id === 'twig') return $twig;
            return null;
        });
        $this->container->method('has')->willReturnCallback(function ($id) {
            if ($id === 'twig') return true;
            return false;
        });

        $twig->method('render')->willReturn('<html>Email Content</html>');

        // Mailer Expectation
        $this->emailSenderService->expects($this->once())->method('sendTemplatedEmail');
        // Logger Expectation
        $this->logger->expects($this->never())->method('critical');

        // Execute
        $response = $this->controller->registerApi($request, $userPasswordHasher, $urlGenerator);

        // Verify
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(201, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Registered Successfully.', $content['message']);
    }

    public function testRegisterApiUserAlreadyExists(): void
    {
        $requestData = [
            'email' => 'existing@example.com',
            'password' => 'secret123',
            'firstName' => 'Jane',
            'lastName' => 'Doe'
        ];
        $request = new Request([], [], [], [], [], [], json_encode($requestData));

        $userPasswordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);

        $userRepo = $this->createMock(EntityRepository::class);
        $this->entityManager->method('getRepository')->with(User::class)->willReturn($userRepo);

        // User FOUND
        $userRepo->expects($this->once())->method('findOneBy')->willReturn(new User());

        $response = $this->controller->registerApi($request, $userPasswordHasher, $urlGenerator);

        $this->assertEquals(409, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('User already exists', $content['error']);
    }

    public function testRegisterApiInvalidData(): void
    {
        // Missing lastName
        $requestData = [
            'email' => 'incomplete@example.com',
            'password' => 'secret123',
            'firstName' => 'John'
        ];
        $request = new Request([], [], [], [], [], [], json_encode($requestData));

        $userPasswordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);

        $response = $this->controller->registerApi($request, $userPasswordHasher, $urlGenerator);

        $this->assertEquals(400, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Invalid data', $content['error']);
    }
}
