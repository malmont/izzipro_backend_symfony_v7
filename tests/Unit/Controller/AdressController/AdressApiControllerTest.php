<?php

namespace App\Tests\Unit\Controller\AdressController;

use App\Controller\AdressController\AdressApiController;
use App\Dto\AdressInputDTO;
use App\Entity\Adress;
use App\Entity\User;
use App\Services\AdressService\AddressVerificationService;
use App\Services\GemsuiteImporterService\GemsuiteClientUpdater;
use App\Services\TenantCacheService;
use App\UseCase\AdressUseCase\CreateAdressUseCase;
use App\UseCase\AdressUseCase\DeleteAdressUseCase;
use App\UseCase\AdressUseCase\EditAdressUseCase;
use App\UseCase\AdressUseCase\GetUserAdressesUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Cache\ItemInterface;

class AdressApiControllerTest extends TestCase
{
    private $getUserAdressesUseCase;
    private $createAdressUseCase;
    private $editAdressUseCase;
    private $deleteAdressUseCase;
    private $verifier;
    private $cache;
    private $gemsuiteUpdater;
    private $controller;
    private $container;
    private $tokenStorage;
    private $serializer;
    private $validator;
    private $em;

    protected function setUp(): void
    {
        $this->getUserAdressesUseCase = $this->createMock(GetUserAdressesUseCase::class);
        $this->createAdressUseCase = $this->createMock(CreateAdressUseCase::class);
        $this->editAdressUseCase = $this->createMock(EditAdressUseCase::class);
        $this->deleteAdressUseCase = $this->createMock(DeleteAdressUseCase::class);
        $this->verifier = $this->createMock(AddressVerificationService::class);
        $this->cache = $this->createMock(TenantCacheService::class);
        $this->gemsuiteUpdater = $this->createMock(GemsuiteClientUpdater::class);
        $this->validator = $this->createMock(ValidatorInterface::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $this->controller = new AdressApiController(
            $this->getUserAdressesUseCase,
            $this->createAdressUseCase,
            $this->editAdressUseCase,
            $this->deleteAdressUseCase,
            $this->verifier,
            $this->cache,
            $this->gemsuiteUpdater
        );

        $this->container = $this->createMock(ContainerInterface::class);
        $this->tokenStorage = $this->createMock(TokenStorageInterface::class);
        $this->serializer = $this->createMock(SerializerInterface::class);

        $this->controller->setContainer($this->container);
    }

    private function mockUser(?User $user): void
    {
        $this->container->method('has')
            ->willReturnMap([
                ['security.token_storage', true],
                ['serializer', true]
            ]);

        $this->container->method('get')
            ->willReturnMap([
                ['security.token_storage', $this->tokenStorage],
                ['serializer', $this->serializer]
            ]);

        $token = $this->createMock(TokenInterface::class);
        $this->tokenStorage->method('getToken')->willReturn($token);
        $token->method('getUser')->willReturn($user);

        if ($user) {
            $this->serializer->method('serialize')
                ->willReturn('{"mocked": "json"}');
        }
    }

    public function testGetUserAdressesSuccess(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);
        $this->mockUser($user);

        $adresses = [new Adress(), new Adress()];

        $this->cache->expects($this->once())
            ->method('get')
            ->willReturn($adresses);

        $response = $this->controller->getUserAdresses();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testCreateAdressSuccess(): void
    {
        $user = new User(); // Real User object to test setPrimaryAddress
        $this->mockUser($user);

        $requestData = [
            'addressLineOne' => '123 Test St',
            'city' => 'Test City',
            'country' => 'Test Country',
            'zipCode' => '12345',
            'isPrimary' => true
        ];
        $request = new Request([], [], [], [], [], [], json_encode($requestData));

        $this->validator->expects($this->once())
            ->method('validate')
            ->willReturn($this->createMock(ConstraintViolationListInterface::class));

        $this->verifier->expects($this->once())
            ->method('verify')
            ->willReturn([
                'street1' => '123 Test St',
                'street2' => null,
                'city' => 'Test City',
                'province' => 'Test Province',
                'postal_code' => '12345',
                'country' => 'Test Country'
            ]);

        $createdAdress = new Adress();
        $this->createAdressUseCase->expects($this->once())
            ->method('execute')
            ->willReturn($createdAdress);

        $this->gemsuiteUpdater->expects($this->once())
            ->method('syncAddress')
            ->with($user, $createdAdress);

        $this->em->expects($this->once())->method('flush');

        $response = $this->controller->createAdress($request, $this->validator, $this->em);

        $this->assertEquals(Response::HTTP_CREATED, $response->getStatusCode());
        $this->assertSame($createdAdress, $user->getPrimaryAddress());
    }

    public function testEditAdressSuccess(): void
    {
        $user = new User();
        $this->mockUser($user);

        $adress = new Adress();
        $adress->setUserAdress($user);

        $requestData = [
            'addressLineOne' => '456 New St',
            'isPrimary' => true
        ];
        $request = new Request([], [], [], [], [], [], json_encode($requestData));

        $this->validator->expects($this->once())
            ->method('validate')
            ->willReturn($this->createMock(ConstraintViolationListInterface::class));

        $this->verifier->expects($this->once())
            ->method('verify')
            ->willReturn([
                'street1' => '456 New St',
                'street2' => null,
                'city' => 'City',
                'province' => 'Province',
                'postal_code' => '00000',
                'country' => 'FR'
            ]);

        $this->editAdressUseCase->expects($this->once())
            ->method('execute');

        $this->gemsuiteUpdater->expects($this->once())
            ->method('syncAddress');

        $this->em->expects($this->once())->method('flush');

        $response = $this->controller->editAdress($request, $this->validator, $adress, $this->em);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame($adress, $user->getPrimaryAddress());
    }

    public function testSetPrimaryAddressSuccess(): void
    {
        $user = new User();
        $this->mockUser($user);

        $adress = new Adress();
        $adress->setUserAdress($user);

        $this->em->expects($this->once())->method('persist')->with($user);
        $this->em->expects($this->once())->method('flush');
        $this->gemsuiteUpdater->expects($this->once())->method('syncAddress');

        $response = $this->controller->setPrimaryAddress($adress, $this->em);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame($adress, $user->getPrimaryAddress());
    }

    public function testDeleteAdressSuccess(): void
    {
        $user = new User();
        $this->mockUser($user);

        $adress = new Adress();
        $adress->setUserAdress($user);

        $this->deleteAdressUseCase->expects($this->once())
            ->method('execute')
            ->with($adress);

        $response = $this->controller->deleteAdress($adress);

        $this->assertEquals(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    }
}
