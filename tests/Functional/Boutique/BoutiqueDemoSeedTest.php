<?php

namespace App\Tests\Functional\Boutique;

use App\Services\BoutiqueDemoService\BoutiqueDemoCatalog;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\BoutiqueDemoUseCase\SeedBoutiqueDemoUseCase;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Boutique de démonstration (app:boutique:seed-demo) : réservée au site demo, rejouable, et ses produits se lisent
 * et se réservent par les routes publiques (location sans ancien enregistrement Vehicle).
 */
class BoutiqueDemoSeedTest extends WebTestCase
{
    public function testOnlyTheDemoSiteCanBeFilled(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ne se remplit que sur demo');
        static::getContainer()->get(SeedBoutiqueDemoUseCase::class)->execute('karaandb', 'client@example.invalid');
    }

    public function testSeedCreatesEveryCaseOnceAndProductsAreBookable(): void
    {
        $client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $seeder = static::getContainer()->get(BoutiqueDemoSeeder::class);

        $first = $seeder->seed('client-demo@example.invalid', false, false);
        $this->assertNotNull($first['customerPassword'], 'client créé : mot de passe tiré');
        $db = $provider->getEntityManager()->getConnection();
        $this->assertSame(count(BoutiqueDemoCatalog::products()), (int) $db->fetchOne("SELECT COUNT(*) FROM product WHERE code LIKE 'DEMO-%'"));
        // Transporteurs 1, 2 et 6 présents (une ligne déjà là, comme dans la base modèle des tests, n'est pas écrasée)
        $this->assertSame([1, 2, 6], array_values(array_intersect([1, 2, 6], array_map('intval', $db->fetchFirstColumn('SELECT id FROM carrier ORDER BY id')))));
        $this->assertSame(7, (int) $db->fetchOne('SELECT COUNT(*) FROM status_commande'));

        $second = $seeder->seed('client-demo@example.invalid', false, false);
        $this->assertSame([], array_filter($second['report'], fn ($line) => str_starts_with($line, 'Produit')), 'rien n\'est recréé');
        $this->assertNull($second['customerPassword'], 'mot de passe inchangé');
        $this->assertSame(count(BoutiqueDemoCatalog::products()), (int) $db->fetchOne("SELECT COUNT(*) FROM product WHERE code LIKE 'DEMO-%'"));

        $get = function (string $path) use ($client): array {
            $client->request('GET', 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json']);
            $this->assertSame(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());

            return json_decode($client->getResponse()->getContent(), true);
        };
        $paddle = $get('/api/products/by-slug/planche-a-pagaie-gonflable');
        $this->assertSame([true, true], [$paddle['sale']['enabled'], $paddle['rental']['enabled']]);
        $this->assertSame(9500, $paddle['rental']['rates'][0]['dayRate'], 'forfait en cents, venu de la catégorie');

        // Location d'un produit qui n'est pas un véhicule : jusqu'au 08/10/2026, toujours « complet »
        $kayak = (int) $db->fetchOne("SELECT id FROM product WHERE code = 'DEMO-KAYAK'");
        $day = (new \DateTimeImmutable('+3 days'))->format('Y-m-d');
        $check = $get("/api/booking/check/$kayak?start={$day}T10:00:00&end={$day}T12:00:00&quantity=2");
        $this->assertTrue($check['available']);
        $this->assertSame(6, $check['remaining_stock']);
        $calendar = $get("/api/booking/calendar/$kayak?start={$day}T09:00:00&end={$day}T12:00:00");
        $this->assertSame([6], array_values(array_unique(array_column($calendar, 'remaining'))));
    }
}
