<?php

namespace App\Tests\Functional\Gestion;

use App\Controller\Admin\ProductCrudController;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\PosOfflineService\PosOfflinePackService;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Lot P5b (11/11/2026, `docs/promotions-gestion.md` § 7, contrat validé par l'application) : dates de promotion stockées en UTC, saisies en heure
 * du magasin. PATCH (date seule = jour du magasin, heure sans décalage = heure du magasin, décalage converti), même instant pour le prix effectif
 * (panier, caisse en ligne), la boutique, le pack hors ligne et la règle pos-offline-v1, champs ISO additifs, champ EasyAdmin au fuseau du magasin,
 * remise en UTC des promotions existantes (simulation, écriture, journal, rejeu sans effet). Magasin à Toronto le temps du test.
 */
class PromotionTimezoneTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private KernelBrowser $client;
    private string $token = '';
    private mixed $previousTz = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $conn = $this->em()->getConnection();
        $this->previousTz = $conn->fetchOne('SELECT pos_timezone FROM entreprise ORDER BY id LIMIT 1');
        $conn->executeStatement("UPDATE entreprise SET pos_timezone = 'America/Toronto'");
        $this->login();
    }

    protected function tearDown(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE entreprise SET pos_timezone = ?', [$this->previousTz === false ? null : $this->previousTz]);
        $conn->executeStatement("DELETE FROM gestion_promotion_tz_fix WHERE product_id IN (SELECT id FROM product WHERE slug LIKE 'p5b-%')");
        $conn->executeStatement("DELETE FROM product_variant WHERE product_id IN (SELECT id FROM product WHERE slug LIKE 'p5b-%')");
        $conn->executeStatement("DELETE FROM product WHERE slug LIKE 'p5b-%'");
        parent::tearDown();
    }

    public function testPatchFormatsAndSameInstantEverywhere(): void
    {
        $conn = $this->em()->getConnection();
        $id = $this->product('Fuseau');
        $read = fn () => array_map(fn ($v) => $v === null ? null : substr((string) $v, 0, 19), array_values($conn->fetchAssociative('SELECT special_price_from, special_price_to FROM product WHERE id = ?', [$id])));

        // Date seule : jour du magasin (hiver : 00:00 Toronto = 05:00 UTC ; fin 23:59:59 = 04:59:59 le lendemain)
        $this->patch($id, ['specialPrice' => 1500, 'specialPriceFrom' => '2026-11-12', 'specialPriceTo' => '2026-11-20']);
        $this->assertSame(['2026-11-12 05:00:00', '2026-11-21 04:59:59'], $read());
        // Été : décalage de 4 h
        $this->patch($id, ['specialPriceFrom' => '2026-07-01', 'specialPriceTo' => '2026-07-01']);
        $this->assertSame(['2026-07-01 04:00:00', '2026-07-02 03:59:59'], $read());
        // Avec décalage : même instant ; heure sans décalage : heure du magasin ; UTC explicite : inchangé
        $this->patch($id, ['specialPriceFrom' => '2026-11-12T00:00:00-05:00', 'specialPriceTo' => '2026-11-12T23:30:00']);
        $this->assertSame(['2026-11-12 05:00:00', '2026-11-13 04:30:00'], $read());
        $this->patch($id, ['specialPriceFrom' => '2026-11-12T00:00:00+00:00', 'specialPriceTo' => '2026-11-12T10:00:00Z']);
        $this->assertSame(['2026-11-12 00:00:00', '2026-11-12 10:00:00'], $read());

        // Même instant partout : une minute avant le début, rien n'est actif ; une minute après, tout l'est
        $utc = new \DateTimeZone('UTC');
        foreach ([['+1 minute', false], ['-1 minute', true]] as [$shift, $active]) {
            $from = (new \DateTimeImmutable('now', $utc))->modify($shift);
            $this->patch($id, ['specialPriceFrom' => $from->format(\DateTimeInterface::ATOM), 'specialPriceTo' => $from->modify('+2 days')->format(\DateTimeInterface::ATOM)]);
            $this->em()->clear();
            $product = $this->em()->find(Product::class, $id);
            $this->assertSame($active ? 1500.0 : 2000.0, $product->getEffectivePrice(), "prix effectif (panier, caisse en ligne) $shift");
            $pricing = $this->get("/api/products/$id")['pricing'] ?? null;
            $this->assertNotNull($pricing, 'pricing de la boutique');
            $this->assertSame([$active, $active ? 1500 : 2000], [$pricing['special']['active'], $pricing['amount']], "boutique $shift");
            // Pack hors ligne : l'instant porté en ISO +00:00, et la règle pos-offline-v1 (étape 1) donne la même réponse à l'heure de la vente
            $pack = static::getContainer()->get(PosOfflinePackService::class)->build(null);
            $packed = array_values(array_filter($pack['products'], fn ($p) => $p['id'] === $id))[0];
            $this->assertSame($from->format(\DateTimeInterface::ATOM), $packed['specialPriceFrom']);
            $soldAt = new \DateTimeImmutable('now', $utc);
            $offline = $soldAt >= new \DateTimeImmutable($packed['specialPriceFrom']) && $soldAt <= new \DateTimeImmutable($packed['specialPriceTo']) ? $packed['specialPrice'] : $packed['price'];
            $this->assertSame($active ? 1500 : 2000, $offline, "caisse hors ligne $shift");
        }

        // Champs ISO additifs des anciennes sorties ; format ancien inchangé
        $detail = $this->get("/api/productsid/$id");
        $this->assertArrayHasKey('specialPriceFromIso', $detail, implode(',', array_keys($detail)));
        $this->assertStringEndsWith('+00:00', $detail['specialPriceFromIso']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $detail['specialPriceFrom']);

        // EasyAdmin : saisie et affichage en heure du magasin
        $fields = iterator_to_array(static::getContainer()->get(ProductCrudController::class)->configureFields('edit'), false);
        $tz = [];
        foreach ($fields as $f) {
            if (in_array($f->getAsDto()->getProperty(), ['specialPriceFrom', 'specialPriceTo'], true)) {
                $tz[] = $f->getAsDto()->getCustomOption('timezone');
            }
        }
        $this->assertSame(['America/Toronto', 'America/Toronto'], $tz);
    }

    public function testExistingPromotionsShiftedOnceWithJournal(): void
    {
        $conn = $this->em()->getConnection();
        $id = $this->product('Ancienne');
        $conn->executeStatement("UPDATE product SET special_price = 1500, special_price_from = '2026-01-15 00:00:00', special_price_to = '2026-07-15 23:59:00' WHERE id = ?", [$id]);
        $run = function (bool $apply) use ($id): string {
            $tester = new CommandTester((new Application(static::$kernel))->find('app:gestion:promotions:fuseau'));
            $tester->execute(['--tenant' => MV_TEST_TENANT_CODE, '--ids' => (string) $id] + ($apply ? ['--apply' => true] : []));

            return $tester->getDisplay();
        };
        $read = fn () => array_map(fn ($v) => substr((string) $v, 0, 19), array_values($conn->fetchAssociative('SELECT special_price_from, special_price_to FROM product WHERE id = ?', [$id])));
        $this->assertStringContainsString('simulation', $run(false));
        $this->assertSame(['2026-01-15 00:00:00', '2026-07-15 23:59:00'], $read(), 'simulation : rien d\'écrit');
        $run(true);
        $this->assertSame(['2026-01-15 05:00:00', '2026-07-16 03:59:00'], $read(), 'heure du magasin voulue : hiver + 5 h, été + 4 h');
        $this->assertSame(['2026-01-15 00:00:00', '2026-07-15 23:59:00', 'America/Toronto'], array_map(fn ($v) => substr((string) $v, 0, 19), array_values($conn->fetchAssociative('SELECT old_from, old_to, timezone FROM gestion_promotion_tz_fix WHERE product_id = ?', [$id]))));
        $this->assertStringContainsString('déjà corrigé', $run(true));
        $this->assertSame(['2026-01-15 05:00:00', '2026-07-16 03:59:00'], $read(), 'rejeu sans effet');
        $this->assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM gestion_promotion_tz_fix WHERE product_id = ?', [$id]));
    }

    // ----- helpers -----

    private function product(string $name): int
    {
        $id = (int) $this->em()->getConnection()->fetchOne("INSERT INTO product (id, name, price, isbestseller, isfeatured, isnewarrival, isspecialoffer, is_accessory, is_landing_page, quantity, created_at, slug, product_type, sale_enabled, is_web, is_pos) VALUES (nextval('product_id_seq'), ?, 2000, false, false, false, false, false, false, 5, NOW(), ?, 'standard', true, true, true) RETURNING id",
            ["Promo $name", 'p5b-' . bin2hex(random_bytes(5))]);
        $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $id)]);

        return $id;
    }

    private function patch(int $id, array $body): void
    {
        $this->client->request('PATCH', "/api/products/$id", [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token], json_encode($body));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), substr((string) $this->client->getResponse()->getContent(), 0, 400));
    }

    private function get(string $route): array
    {
        $this->client->request('GET', $route, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 300));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    protected function em(): EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }

    private function login(): void
    {
        $email = 'gestion-p5b-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('Fuseau')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        static::getContainer()->get(CacheInvalidationSubscriber::class)->invalidateWrittenTenants();
    }
}
