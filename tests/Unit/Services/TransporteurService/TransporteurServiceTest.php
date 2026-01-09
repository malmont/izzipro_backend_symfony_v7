<?php

namespace App\Tests\Unit\Services\TransporteurService;

use App\Entity\Transporteur;
use App\Services\TenantEntityManagerProvider;
use App\Services\TransporteurService\TransporteurService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

class TransporteurServiceTest extends TestCase
{
    private $emProvider;
    private $entityManager;
    private $transporteurService;

    protected function setUp(): void
    {
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->emProvider->method('getEntityManager')
            ->willReturn($this->entityManager);

        $this->transporteurService = new TransporteurService($this->emProvider);
    }

    public function testGetAllTransporteurs(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $expectedTransporteurs = [new Transporteur(), new Transporteur()];

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(Transporteur::class)
            ->willReturn($repository);

        $repository->expects($this->once())
            ->method('findAll')
            ->willReturn($expectedTransporteurs);

        $result = $this->transporteurService->getAllTransporteurs();

        $this->assertSame($expectedTransporteurs, $result);
    }

    public function testCreateTransporteur(): void
    {
        $name = 'Test Carrier';
        $logo = 'logo.png';
        $contact = 'contact@test.com';

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(Transporteur::class));

        $this->entityManager->expects($this->once())
            ->method('flush');

        $result = $this->transporteurService->createTransporteur($name, $logo, $contact);

        $this->assertInstanceOf(Transporteur::class, $result);
        $this->assertEquals($name, $result->getName());
        $this->assertEquals($logo, $result->getLogo());
        $this->assertEquals($contact, $result->getContact());
    }

    public function testDeleteTransporteur(): void
    {
        $transporteur = new Transporteur();

        $this->entityManager->expects($this->once())
            ->method('remove')
            ->with($transporteur);

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->transporteurService->deleteTransporteur($transporteur);
    }
}
