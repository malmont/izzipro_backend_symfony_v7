<?php

namespace App\Tests\Unit\UseCase\ContactUseCase;

use App\Dto\ContactSubmitDto;
use App\Entity\Entreprise;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantConnectionManager;
use App\Services\GemsuiteImporterService\GemsuiteClientManager;
use App\UseCase\ContactUseCase\SubmitContactUseCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SubmitContactUseCaseTest extends TestCase
{
    public function testExecuteSuccess(): void
    {
        $emailSenderService = $this->createMock(EmailSenderService::class);
        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $logger = $this->createMock(LoggerInterface::class);
        $tenantManager = $this->createMock(TenantConnectionManager::class);
        $gemsuiteClientManager = $this->createMock(GemsuiteClientManager::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $repository = $this->createMock(EntityRepository::class);

        $emProvider->method('getEntityManager')->willReturn($entityManager);
        $entityManager->method('getRepository')->with(Entreprise::class)->willReturn($repository);

        $entreprise = $this->createMock(Entreprise::class);
        $entreprise->method('getEmail')->willReturn('company@example.com');
        $repository->method('findOneBy')->willReturn($entreprise);

        $dto = new ContactSubmitDto();
        $dto->name = 'Jane Doe';
        $dto->email = 'jane.doe@example.com';
        $dto->phone = '514-987-6543';
        $dto->service = 'Pièces';
        $dto->message = 'Hello, I need some parts.';

        // Expect email sending: one to merchant, one to client/requester
        $emailSenderService->expects($this->exactly(2))
            ->method('sendTemplatedEmail')
            ->withConsecutive(
                [
                    'company@example.com',
                    'Nouveau message de contact - Pièces',
                    'emails/contact_form.html.twig',
                    ['contact' => $dto],
                    'fr',
                    'my-tenant.com',
                    null,
                    'jane.doe@example.com'
                ],
                [
                    'jane.doe@example.com',
                    'Accusé de réception - Pièces',
                    'emails/contact_acknowledgement.html.twig',
                    ['contact' => $dto],
                    'fr',
                    'my-tenant.com'
                ]
            );

        // Expect gemsuite prospect creation with type 2
        $tenantManager->method('getCurrentTenantCode')->willReturn('tenant456');
        $gemsuiteClientManager->expects($this->once())
            ->method('findOrCreateClient')
            ->with(
                'jane.doe@example.com',
                'Jane',
                'Doe',
                'tenant456',
                true,
                null,
                '514-987-6543',
                2 // Type 2 for contact form
            );

        $useCase = new SubmitContactUseCase(
            $emailSenderService,
            $emProvider,
            $logger,
            $tenantManager,
            $gemsuiteClientManager
        );

        $useCase->execute($dto, 'my-tenant.com', 'fr');
    }
}
