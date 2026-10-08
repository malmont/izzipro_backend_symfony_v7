<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\BookingConfiguration;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\VehicleProduct;
use App\Enum\ProductMode;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Contrat « boutique réglable » d'un produit (ProductCommerceDto) sur les routes publiques : modes explicites, montants
 * en cents, réglages de réservation, fiche véhicule, prix de variante. Les anciens champs restent.
 */
class ProductContractApiTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testProductSoldAndRentedExposesExplicitModesAndCents(): void
    {
        $em = $this->em();
        $slug = 'kayak-' . bin2hex(random_bytes(3));
        $product = (new Product())->setName('Kayak de mer')->setSlug($slug)->setPrice(199950)->setIsWeb(true)
            ->setSaleEnabled(true)->setRentalEnabled(true)->setCustomizable(true)
            ->setSpecialPrice(145000)->setSpecialPriceFrom(new \DateTimeImmutable('-1 day'))->setSpecialPriceTo(new \DateTimeImmutable('+1 day'));
        $variant = (new ProductVariant())->setStockQuantity(3)->setPrice(249900);
        $product->addVariant($variant);
        $config = (new BookingConfiguration())->setProduct($product)->setGranularity('hours')->setStockQuantity(4)->setMinDuration(2)->setBufferTime(15)
            ->setMaxDuration(8)->setOpeningStart('09:00')->setOpeningEnd('18:00')->setHalfDays([['label' => 'Matin', 'start' => '09:00', 'end' => '13:00']])
            ->setDeposit(150000)->setExtraPassengerFee(2500)->setArrivalLeadMinutes(45)->setCancellationPolicy('Aucun remboursement dans les 48 h.')
            ->setIncluded(['Gilet', 'Pagaie'])->setAllowedDates([]);
        $em->persist($product);
        $em->persist($config);
        $em->flush();
        // Devise du site : fiche entreprise (§ 7), lue par tous les montants de la boutique
        $em->getConnection()->executeStatement("UPDATE entreprise SET currency = 'EUR'");

        $this->assertSame(ProductMode::RETAIL, $product->getMode(), 'ancien mode : vente + location = retail');

        try {
            $body = $this->get("/api/products/by-slug/$slug");
            $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/stripe-config', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
            $stripeConfig = json_decode($this->client->getResponse()->getContent(), true);
        } finally {
            $em->getConnection()->executeStatement("UPDATE entreprise SET currency = 'CAD'");
        }
        if ($this->client->getResponse()->getStatusCode() === 200) {
            $this->assertSame('EUR', $stripeConfig['currency'], 'stripe-config porte la devise du site');
        }

        $this->assertSame('standard', $body['kind']);
        $this->assertSame(['enabled' => true], $body['sale']);
        $this->assertTrue($body['rental']['enabled']);
        $this->assertSame(['enabled' => false], $body['subscription']);
        $this->assertTrue($body['customizable']);
        $this->assertNull($body['vehicleDetails']);
        $this->assertSame(['currency' => 'EUR', 'regular' => 199950, 'amount' => 145000], array_intersect_key($body['pricing'], array_flip(['currency', 'regular', 'amount'])));
        $this->assertSame(145000, $body['pricing']['special']['amount']);
        $this->assertTrue($body['pricing']['special']['active']);
        $this->assertSame(199950.0, (float) $body['price'], 'ancien champ price conservé (cents, carrousel des landing pages)');

        $rental = $body['rental'];
        $this->assertSame(['hours', 2, 8, 4, 15], [$rental['granularity'], $rental['minDuration'], $rental['maxDuration'], $rental['stockQuantity'], $rental['bufferTime']]);
        $this->assertSame(['start' => '09:00', 'end' => '18:00'], $rental['openingHours']);
        $this->assertSame([['label' => 'Matin', 'start' => '09:00', 'end' => '13:00']], $rental['halfDays']);
        $this->assertSame([150000, 2500, 45], [$rental['deposit'], $rental['extraPassengerFee'], $rental['arrivalLeadMinutes']]);
        $this->assertSame(['Gilet', 'Pagaie'], $rental['included']);
        $this->assertNull($rental['allowedDates'], 'liste vide = toutes les dates');
        $this->assertSame([], $rental['rates'], 'aucun forfait de location sur ce produit');
        $this->assertSame('hours', $body['bookingConfig']['granularity'], 'ancien bookingConfig conservé');

        $this->assertSame(249900, $body['variants'][0]['price']);
        $this->assertSame(3, $body['variants'][0]['stockQuantity']);
        $this->assertArrayHasKey('categories', $body);

        $byId = $this->get('/api/productsid/' . $product->getId());
        $this->assertSame(array_diff_key($body['pricing'], ['currency' => 1]), array_diff_key($byId['pricing'], ['currency' => 1]), 'même contrat par identifiant (devise remise à CAD entre les deux)');
    }

    public function testVehicleIsServedBySlugWithItsDetails(): void
    {
        $em = $this->em();
        $slug = 'sea-doo-' . bin2hex(random_bytes(3));
        $vehicle = (new VehicleProduct())->setName('Sea-Doo Spark')->setSlug($slug)->setPrice(899900)->setIsWeb(true)
            ->setBrand('Sea-Doo')->setModel('Spark')->setYear(2025)->setVehicleCondition('neuf');
        $vehicle->setMode(ProductMode::BOOKING);
        $em->persist($vehicle);
        $em->flush();

        $body = $this->get("/api/products/by-slug/$slug");

        $this->assertSame('vehicle', $body['kind']);
        $this->assertSame(['Sea-Doo', 'Spark', 2025, 'neuf'], [$body['vehicleDetails']['brand'], $body['vehicleDetails']['model'], $body['vehicleDetails']['year'], $body['vehicleDetails']['condition']]);
        $this->assertSame([false, true], [$body['sale']['enabled'], $body['rental']['enabled']], 'ancien mode booking = location seule');
        $this->assertSame('booking', $body['mode']);
        $this->assertSame(899900, $body['pricing']['regular']);
        $this->assertNull($body['pricing']['special']);
        $this->assertNull($body['rental']['granularity'], 'location activée sans configuration : champs à null');
    }

    private function get(string $path): array
    {
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json']);
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent(), true);
    }

    private function em(): \Doctrine\ORM\EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }
}
