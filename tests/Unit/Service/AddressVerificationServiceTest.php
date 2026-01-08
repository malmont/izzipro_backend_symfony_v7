<?php

namespace App\Tests\Unit\Service;

use App\Services\AdressService\AddressVerificationService;
use App\Services\ShippingService\EasyPostService;
use EasyPost\EasyPostClient; // <-- Important : on importe la vraie classe
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AddressVerificationServiceTest extends TestCase
{
    public function testVerifyReturnsNormalizedAddressOnSuccess(): void
    {
        // 1. Création de la réponse simulée (Payload)
        $mockResult = new \stdClass();
        $mockResult->verifications = new \stdClass();
        $mockResult->verifications->delivery = new \stdClass();
        $mockResult->verifications->delivery->errors = [];

        $mockResult->street1 = '123 Normalized St';
        $mockResult->street2 = null;
        $mockResult->city = 'San Francisco';
        $mockResult->state = 'CA';
        $mockResult->zip = '94105';
        $mockResult->country = 'US';

        // 2. Mock du "Service Adresse" interne d'EasyPost
        // C'est l'objet qui répond à ->create()
        $mockAddressResource = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['create'])
            ->getMock();

        $mockAddressResource->expects($this->once())
            ->method('create')
            ->willReturn($mockResult);

        // 3. Mock du Client EasyPost (Le point bloquant précédent)
        // On mock la vraie classe pour satisfaire le typage strict
        $mockClient = $this->createMock(EasyPostClient::class);

        // On injecte notre fausse ressource d'adresse dans la propriété publique du client
        // Note : PHPUnit permet d'écrire sur les propriétés d'un mock
        $mockClient->address = $mockAddressResource;

        // 4. Mock du Service Wrapper
        $easyPostService = $this->createMock(EasyPostService::class);
        $easyPostService->method('getClient')->willReturn($mockClient); // <-- Ici, on renvoie le bon type !

        // 5. Exécution
        $service = new AddressVerificationService($easyPostService);

        $normalized = $service->verify([
            'street1' => '123 main st',
            'city' => 'sf',
            'province' => 'ca',
            'postal_code' => '94105',
            'country' => 'US'
        ]);

        // 6. Vérification
        $this->assertEquals('123 Normalized St', $normalized['street1']);
        $this->assertEquals('CA', $normalized['province']);
    }

    public function testVerifyThrowsExceptionOnInvalidAddress(): void
    {
        // 1. Payload avec erreurs (Simulé)
        $mockResult = new \stdClass();
        $mockResult->verifications = new \stdClass();
        $mockResult->verifications->delivery = new \stdClass();

        $error1 = new \stdClass();
        $error1->message = 'Address not found';

        $mockResult->verifications->delivery->errors = [$error1];

        // 2. Mock Address Resource
        $mockAddressResource = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['create'])
            ->getMock();

        $mockAddressResource->expects($this->once())
            ->method('create')
            ->willReturn($mockResult);

        // 3. Mock Client
        $mockClient = $this->createMock(EasyPostClient::class);
        $mockClient->address = $mockAddressResource;

        // 4. Service Wrapper
        $easyPostService = $this->createMock(EasyPostService::class);
        $easyPostService->method('getClient')->willReturn($mockClient);

        // 5. Assertions
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Adresse invalide : Address not found');

        // 6. Exécution avec TOUS les champs requis (Même faux)
        $service = new AddressVerificationService($easyPostService);

        // CORRECTION ICI : On fournit un tableau complet pour ne pas planter PHP
        $service->verify([
            'street1'     => 'Nowhere',
            'street2'     => null,
            'city'        => 'Lost City',
            'province'    => 'ZZ',
            'postal_code' => '00000',
            'country'     => 'US'
        ]);
    }

    public function testVerifyPropagatesEasyPostExceptions(): void
    {
        // 1. Mock Address Resource qui lance une exception
        $mockAddressResource = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['create'])
            ->getMock();

        $mockAddressResource->expects($this->once())
            ->method('create')
            ->willThrowException(new \Exception('EasyPost API Down'));

        // 2. Mock Client
        $mockClient = $this->createMock(EasyPostClient::class);
        $mockClient->address = $mockAddressResource;

        // 3. Mock Service Wrapper
        $easyPostService = $this->createMock(EasyPostService::class);
        $easyPostService->method('getClient')->willReturn($mockClient);

        // 4. Vérification que l'exception remonte
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('EasyPost API Down');

        $service = new AddressVerificationService($easyPostService);

        $service->verify([
            'street1'     => '123 Main St',
            'city'        => 'Test',
            'province'    => 'TS',
            'postal_code' => '12345',
            'country'     => 'TS'
        ]);
    }
}
