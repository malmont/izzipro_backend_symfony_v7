<?php

namespace App\Tests\Unit\Service;

use App\Entity\AddressEntreprise;
use App\Entity\Entreprise;
use App\Services\ShippingService\ShipmentAddressBuilder;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;

class ShipmentAddressBuilderTest extends TestCase
{
    // --- TEST 1 : LOGIQUE PURE (buildTo) ---

    public function testBuildToMapsArrayCorrectly(): void
    {
        // Pas besoin de Mocks ici, la méthode n'utilise pas le Provider
        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $builder = new ShipmentAddressBuilder($emProvider);

        $input = [
            'name' => 'Jean Dupont',
            'street1' => '123 Rue Principale',
            'city' => 'Paris',
            'province' => 'IDF',
            'postal_code' => '75000',
            'country' => 'FR',
            'contactNumber' => '0102030405'
            // street2 manquant volontairement pour tester le null par défaut
        ];

        $result = $builder->buildTo($input);

        $this->assertEquals('Jean Dupont', $result['name']);
        $this->assertEquals('75000', $result['zip']); // Vérifie le mapping postal_code -> zip
        $this->assertEquals('IDF', $result['state']); // Vérifie le mapping province -> state
        $this->assertNull($result['street2']); // Vérifie la valeur par défaut
    }

    // --- TEST 2 : APPELS BDD (buildFrom) ---

    public function testBuildFromReturnsMappedEntityData(): void
    {
        // 1. MOCK DE L'ADRESSE (Le bout de la chaîne)
        $mockAddress = $this->createMock(AddressEntreprise::class);
        $mockAddress->method('getStreet1')->willReturn('10 Bvd Industriel');
        $mockAddress->method('getCity')->willReturn('Montreal');
        $mockAddress->method('getState')->willReturn('QC');
        $mockAddress->method('getZip')->willReturn('H1H 1H1');
        $mockAddress->method('getCountry')->willReturn('CA');
        $mockAddress->method('getPhone')->willReturn('555-1234');
        $mockAddress->method('getEmail')->willReturn('contact@company.com');
        // street2 null
        $mockAddress->method('getStreet2')->willReturn(null);

        // 2. MOCK DE L'ENTREPRISE
        $mockEntreprise = $this->createMock(Entreprise::class);
        $mockEntreprise->method('getName')->willReturn('Ma Super Boite');
        $mockEntreprise->method('getAddressEntreprise')->willReturn($mockAddress);

        // 3. MOCK DOCTRINE (Repo -> EM -> Provider)
        $repo = $this->createMock(ObjectRepository::class);
        $repo->expects($this->once())
            ->method('findOneBy')
            ->willReturn($mockEntreprise);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(Entreprise::class)->willReturn($repo);

        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $emProvider->method('getEntityManager')->willReturn($em);

        // 4. EXECUTION
        $builder = new ShipmentAddressBuilder($emProvider);
        $result = $builder->buildFrom();

        // 5. ASSERTIONS
        $this->assertEquals('Ma Super Boite', $result['name']);
        $this->assertEquals('Montreal', $result['city']);
        $this->assertEquals('H1H 1H1', $result['zip']);
    }

    public function testBuildFromThrowsExceptionIfNoEntrepriseFound(): void
    {
        // Cas : Le repository ne trouve rien
        $repo = $this->createMock(ObjectRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $emProvider->method('getEntityManager')->willReturn($em);

        $builder = new ShipmentAddressBuilder($emProvider);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Pas d\'entreprise configurée');

        $builder->buildFrom();
    }

    public function testBuildFromThrowsExceptionIfNoAddressConfigured(): void
    {
        // Cas : Entreprise trouvée, mais getAddressEntreprise() renvoie null
        $mockEntreprise = $this->createMock(Entreprise::class);
        $mockEntreprise->method('getAddressEntreprise')->willReturn(null);

        $repo = $this->createMock(ObjectRepository::class);
        $repo->method('findOneBy')->willReturn($mockEntreprise);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $emProvider->method('getEntityManager')->willReturn($em);

        $builder = new ShipmentAddressBuilder($emProvider);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Pas d\'adresse entreprise configurée');

        $builder->buildFrom();
    }
}