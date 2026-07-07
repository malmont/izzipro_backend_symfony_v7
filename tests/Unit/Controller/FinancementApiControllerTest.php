<?php

namespace App\Tests\Unit\Controller;

use App\Controller\FinancementApiController;
use App\Dto\FinancementSubmitDto;
use App\Entity\Entreprise;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantConnectionManager;
use App\Services\GemsuiteImporterService\GemsuiteClientManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class FinancementApiControllerTest extends TestCase
{
    private $emailSenderService;
    private $emProvider;
    private $logger;
    private $tenantManager;
    private $gemsuiteClientManager;
    private $validator;
    private $entityManager;
    private $repository;
    private $controller;

    protected function setUp(): void
    {
        $this->emailSenderService = $this->createMock(EmailSenderService::class);
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->tenantManager = $this->createMock(TenantConnectionManager::class);
        $this->gemsuiteClientManager = $this->createMock(GemsuiteClientManager::class);
        $this->validator = $this->createMock(ValidatorInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(EntityRepository::class);

        $this->emProvider->method('getEntityManager')->willReturn($this->entityManager);
        $this->entityManager->method('getRepository')->with(Entreprise::class)->willReturn($this->repository);

        $this->controller = new FinancementApiController(
            $this->emailSenderService,
            $this->emProvider,
            $this->logger,
            $this->tenantManager,
            $this->gemsuiteClientManager
        );

        // Mock Symfony Container for controller base helper json()
        $container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $serializer = $this->createMock(\Symfony\Component\Serializer\SerializerInterface::class);
        $container->method('has')->with('serializer')->willReturn(true);
        $container->method('get')->with('serializer')->willReturn($serializer);
        $serializer->method('serialize')->willReturn('{"success": true}');
        $this->controller->setContainer($container);
    }

    public function testSubmitSuccess(): void
    {
        $payload = [
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john.doe@example.com',
            'phone' => '514-123-4567',
            'birthDate' => '1990-01-01',
            'vehicleType' => 'Roulotte',
            'address' => '123 Rue de la caravane, Montreal, QC',
            'timeAtResidence' => '2 ans',
            'housingStatus' => 'Locataire',
            'monthlyPayment' => 850,
            'monthlyIncome' => 3000,
            'creditScore' => 'Bonne (650+)'
        ];

        $request = new Request([], [], [], [], [], [], json_encode($payload));

        // Mock tenant manager and gemsuite client manager
        $this->tenantManager->method('getCurrentTenantCode')->willReturn('tenant123');
        $this->gemsuiteClientManager->expects($this->once())
            ->method('findOrCreateClient')
            ->with(
                'john.doe@example.com',
                'John',
                'Doe',
                'tenant123',
                true,
                '123 Rue de la caravane, Montreal, QC',
                '514-123-4567'
            );

        // Mock validation passing
        $this->validator->expects($this->once())
            ->method('validate')
            ->willReturn(new ConstraintViolationList());

        // Mock enterprise entity
        $entreprise = $this->createMock(Entreprise::class);
        $entreprise->method('getEmail')->willReturn('company@example.com');
        $this->repository->expects($this->once())
            ->method('findOneBy')
            ->willReturn($entreprise);

        // Expect email sending
        $this->emailSenderService->expects($this->once())
            ->method('sendTemplatedEmail')
            ->with(
                'company@example.com',
                $this->stringContains('Nouvelle demande de financement - John Doe'),
                'emails/financement_form.html.twig',
                $this->callback(function ($context) {
                    return $context['data'] instanceof FinancementSubmitDto && $context['data']->firstName === 'John';
                })
            );

        $response = $this->controller->submit($request, $this->validator);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testSubmitValidationError(): void
    {
        $payload = [
            'lastName' => 'Doe',
            'email' => 'invalid-email',
        ];

        $request = new Request([], [], [], [], [], [], json_encode($payload));

        // Mock validation violation
        $violation = $this->createMock(\Symfony\Component\Validator\ConstraintViolationInterface::class);
        $violation->method('getMessage')->willReturn('Le prénom est requis.');
        $violations = new ConstraintViolationList([$violation]);

        $this->validator->expects($this->once())
            ->method('validate')
            ->willReturn($violations);

        $response = $this->controller->submit($request, $this->validator);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }
}
