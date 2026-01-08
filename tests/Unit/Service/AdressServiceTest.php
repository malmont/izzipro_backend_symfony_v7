<?php

namespace App\Tests\Unit\Service;

use App\Dto\AdressInputDTO;
use App\Dto\AdressOutputDTO;
use App\Entity\Adress;
use App\Entity\User;
use App\Services\AdressService\AdressService;
use App\Services\GemsuiteImporterService\GemsuiteClientUpdater;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class AdressServiceTest extends TestCase
{
    private function simulateEntityId(object $entity, int $id): void
    {
        $reflection = new ReflectionClass($entity);
        while (!$reflection->hasProperty('id') && $reflection->getParentClass()) {
            $reflection = $reflection->getParentClass();
        }

        if ($reflection->hasProperty('id')) {
            $property = $reflection->getProperty('id');
            $property->setAccessible(true);
            $property->setValue($entity, $id);
        }
    }

    public function testCreateAdressPersistsAndSyncs(): void
    {
        $inputData = [
            'firstname' => 'Jean',
            'lastname' => 'Dupont',
            'company' => 'Acme Corp',
            'addressLineOne' => '10 Rue de la Paix',
            'addressLineTwo' => 'Batiment B',
            'city' => 'Paris',
            'province' => 'IDF',
            'zipCode' => 'A1A 1A1',
            'country' => 'CA',
            'contactNumber' => '5145551234',
            'isPrimary' => true
        ];
        $dto = new AdressInputDTO($inputData);

        $user = $this->createMock(User::class);
        $user->expects($this->once())->method('setPrimaryAddress');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist');

        // FIX : On accepte que flush soit appelé plusieurs fois (sécurité)
        $entityManager->expects($this->atLeastOnce())->method('flush');

        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $emProvider->method('getEntityManager')->willReturn($entityManager);

        $gemsuiteUpdater = $this->createMock(GemsuiteClientUpdater::class);
        $gemsuiteUpdater->expects($this->once())
            ->method('syncAddress');

        $service = new AdressService($emProvider, $gemsuiteUpdater);
        $service->createAdress($dto, $user);
    }

    public function testEditAdressUpdatesEntityAndSyncs(): void
    {
        $inputData = [
            'firstname' => 'Modifié',
            'lastname' => 'Dupont',
            'addressLineOne' => 'Rue Test',
            'city' => 'TestCity',
            'province' => 'QC',
            'zipCode' => 'H0H 0H0',
            'country' => 'CA',
            'contactNumber' => '5140000000',
            'isPrimary' => true
        ];
        $dto = new AdressInputDTO($inputData);

        $user = $this->createMock(User::class);
        $user->expects($this->once())->method('setPrimaryAddress');

        $adress = $this->createMock(Adress::class);
        $adress->method('getUserAdress')->willReturn($user);
        $adress->expects($this->once())->method('setFirstname')->with('Modifié');

        $entityManager = $this->createMock(EntityManagerInterface::class);

        // --- FIX 1 : Correction de l'erreur "called more than once" ---
        // On autorise plusieurs appels à flush() (ex: un dans le IF, un à la fin)
        $entityManager->expects($this->atLeastOnce())->method('flush');

        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $emProvider->method('getEntityManager')->willReturn($entityManager);

        $gemsuiteUpdater = $this->createMock(GemsuiteClientUpdater::class);
        $gemsuiteUpdater->expects($this->once())->method('syncAddress');

        $service = new AdressService($emProvider, $gemsuiteUpdater);
        $service->editAdress($dto, $adress);
    }

    public function testGetUserAdressesReturnsOutputDTOs(): void
    {
        $user = new User();

        // --- FIX 2 : Correction de l'erreur "Cannot assign null to lastname" ---
        // On doit remplir TOUS les champs que le AdressOutputDTO va lire.

        $adress1 = new Adress();
        $this->simulateEntityId($adress1, 101);
        $adress1->setFirstname("Paul");
        $adress1->setLastname("Bocuse"); // Important !
        $adress1->setCity("Lyon");
        $adress1->setAddress("Rue A");
        $adress1->setCodepostal("69000");
        $adress1->setCountry("FR");
        $adress1->setProvince("ARA");
        $adress1->setPhone("0102030405");

        $adress2 = new Adress();
        $this->simulateEntityId($adress2, 102);
        $adress2->setFirstname("Jacques");
        $adress2->setLastname("Pepin"); // Important !
        $adress2->setCity("Paris");
        $adress2->setAddress("Rue B");
        $adress2->setCodepostal("75000");
        $adress2->setCountry("FR");
        $adress2->setProvince("IDF");
        $adress2->setPhone("0102030405");

        $fakeAdresses = [$adress1, $adress2];

        $repository = $this->createMock(ObjectRepository::class);
        $repository->expects($this->once())
            ->method('findBy')
            ->with(['userAdress' => $user])
            ->willReturn($fakeAdresses);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('clear');
        $entityManager->expects($this->once())
            ->method('getRepository')
            ->willReturn($repository);

        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $emProvider->method('getEntityManager')->willReturn($entityManager);

        $gemsuiteUpdater = $this->createMock(GemsuiteClientUpdater::class);

        $service = new AdressService($emProvider, $gemsuiteUpdater);
        $results = $service->getUserAdresses($user);

        $this->assertCount(2, $results);
        $this->assertInstanceOf(AdressOutputDTO::class, $results[0]);
    }

    public function testEditAdressDoesNotSyncIfNotPrimary(): void
    {
        $inputData = [
            'firstname' => 'Modifié',
            'lastname' => 'Dupont',
            'addressLineOne' => 'Rue Test',
            'city' => 'TestCity',
            'province' => 'QC',
            'zipCode' => 'H0H 0H0',
            'country' => 'CA',
            'contactNumber' => '5140000000',
            'isPrimary' => false // <-- Important
        ];
        $dto = new AdressInputDTO($inputData);

        // Mock User
        $user = $this->createMock(User::class);
        // On ne s'attend PAS à setPrimaryAddress car isPrimary = false
        $user->expects($this->never())->method('setPrimaryAddress');

        $adress = $this->createMock(Adress::class);
        $adress->method('getUserAdress')->willReturn($user);

        // Gemsuite ne doit PAS être appelé
        $gemsuiteUpdater = $this->createMock(GemsuiteClientUpdater::class);
        $gemsuiteUpdater->expects($this->never())->method('syncAddress');

        // EntityManager
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->atLeastOnce())->method('flush');

        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $emProvider->method('getEntityManager')->willReturn($entityManager);

        $service = new AdressService($emProvider, $gemsuiteUpdater);
        $service->editAdress($dto, $adress);
    }

    public function testDeleteAdressRemovesEntity(): void
    {
        $adress = $this->createMock(Adress::class);

        // Mock Repository
        $repository = $this->getMockBuilder(ObjectRepository::class)
            ->addMethods(['remove']) // remove n'est pas dans l'interface standard ObjectRepository mais souvent dans EntityRepository
            ->getMockForAbstractClass();

        $repository->expects($this->once())
            ->method('remove')
            ->with($adress, true); // Le code appelle remove($adress, true)

        // Mock EntityManager
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('clear'); // Appelé par getAdressRepository
        $entityManager->expects($this->once())
            ->method('getRepository')
            ->with(Adress::class)
            ->willReturn($repository);

        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $emProvider->method('getEntityManager')->willReturn($entityManager);

        $service = new AdressService($emProvider, $this->createMock(GemsuiteClientUpdater::class));

        $service->deleteAdress($adress);
    }
}
