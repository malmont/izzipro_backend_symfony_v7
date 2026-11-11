<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot LOC4, changement de grille de location (11/11/2026, `docs/locations-gestion.md` § 4) : un aperçu sans « appliquer » ne change ni la grille
 * ni le devis ; une grille partagée par plusieurs articles est refusée sans allowShared, avec la liste des articles ; application (hausse en %
 * arrondie au dollar), devis changé ; annulation dans les 24 h refusée si la grille a été retouchée, puis acceptée, devis revenu ; annulation en
 * double, délai dépassé, rien à changer, empreinte, validations. Grille « Tarif nautique » du jeu d'exemple (kayak, ponton…), remise en état après.
 */
class RentalGridApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private string $adminToken = '';
    private int $pack;
    private int $ponton;
    private int $pontonProduct;
    private array $original = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-grille-location@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("DELETE FROM booking WHERE product_id IN (SELECT id FROM product WHERE code IN ('DEMO-PONTON', 'DEMO-KAYAK'))");
        $this->db->executeStatement('DELETE FROM gestion_rental_grid_change');
        $this->pontonProduct = (int) $this->db->fetchOne("SELECT id FROM product WHERE code = 'DEMO-PONTON'");
        $this->ponton = (int) $this->db->fetchOne('SELECT id FROM product_variant WHERE product_id = ? ORDER BY id LIMIT 1', [$this->pontonProduct]);
        $this->pack = (int) $this->db->fetchOne('SELECT rc.rental_pack_id FROM rental_pack_categories rc JOIN product_categories pc ON pc.categories_id = rc.categories_id WHERE pc.product_id = ? ORDER BY rc.rental_pack_id LIMIT 1', [$this->pontonProduct]);
        $this->original = $this->db->fetchAssociative('SELECT hour_rate, half_day_rate, day_rate, week_rate, month_rate FROM rental_pack WHERE id = ?', [$this->pack]);
        $admin = $this->user(['ROLE_ADMIN']);
        $this->adminToken = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($admin);
    }

    protected function tearDown(): void
    {
        $this->db->executeStatement('UPDATE rental_pack SET hour_rate = ?, half_day_rate = ?, day_rate = ?, week_rate = ?, month_rate = ? WHERE id = ?', [...array_values($this->original), $this->pack]);
        $this->db->executeStatement('DELETE FROM gestion_rental_grid_change');
        parent::tearDown();
    }

    public function testPreviewSharedGridApplyAndUndo(): void
    {
        $start = (new \DateTimeImmutable('+20 days'))->format('Y-m-d');
        $end = (new \DateTimeImmutable('+23 days'))->format('Y-m-d');
        $quote = fn () => $this->json($this->request('POST', '/api/cart/quote', ['items' => [['productVariantId' => $this->ponton, 'quantity' => 1, 'booking' => ['start' => "{$start}T09:00:00", 'end' => "{$end}T09:00:00", 'passengers' => 2]]]]));
        $rates = fn () => $this->db->fetchAssociative('SELECT hour_rate, half_day_rate, day_rate, week_rate, month_rate FROM rental_pack WHERE id = ?', [$this->pack]);
        $grids = array_column($this->send('GET', '/api/gestion/locations/grilles', null, 200), null, 'id');
        $products = $grids[$this->pack]['products'];
        $this->assertContains($this->pontonProduct, array_column($products, 'productId'));
        $this->assertGreaterThan(1, count($products), 'grille partagée par plusieurs articles du jeu d\'exemple');
        $this->assertSame(9500, $grids[$this->pack]['rates']['day']);

        // Aperçu : rien ne change, ni la grille ni le devis
        $quote0 = $quote();
        $rates0 = $rates();
        $body = ['rentalPackId' => $this->pack, 'percent' => 10, 'rounding' => 'dollar'];
        $preview = $this->send('POST', '/api/gestion/locations/grilles/apercu', $body, 200);
        $this->assertSame([9500, 10500, true, ['SHARED_GRID']], [$preview['before']['day'], $preview['after']['day'], $preview['shared'], $preview['warnings']], '+10 % arrondi au dollar');
        $this->assertSame(['type' => 'day', 'before' => 9500, 'after' => 10500, 'percent' => 10.5], array_column($preview['changes'], null, 'type')['day']);
        $this->assertSame($rates0, $rates(), 'aperçu : grille intacte');
        $this->assertSame($quote0, $quote(), 'aperçu : devis identique');

        // Grille partagée : refusée sans allowShared, avec la liste des articles
        $this->adminRequest('POST', '/api/gestion/locations/grilles/appliquer', $body + ['previewHash' => $preview['previewHash']]);
        $refused = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame([409, 'SHARED_GRID', $products], [$this->client->getResponse()->getStatusCode(), $refused['error'], $refused['products']]);
        $this->assertSame($rates0, $rates());
        $this->adminRequest('POST', '/api/gestion/locations/grilles/appliquer', $body + ['previewHash' => 'x', 'allowShared' => true]);
        $this->assertSame([409, 'PRICES_CHANGED'], [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true)['error']]);

        // Application avec allowShared : le devis suit la grille
        $change = $this->send('POST', '/api/gestion/locations/grilles/appliquer', $body + ['previewHash' => $preview['previewHash'], 'allowShared' => true], 201);
        $this->assertSame([9500, 10500, array_column($products, 'productId')], [$change['before']['day'], $change['after']['day'], $change['productIds']]);
        $this->assertSame(10500 * 3 + 5000, $quote()['lines'][0]['unitPrice']);
        $this->assertNotNull($change['undoUntil']);

        // Annulation : refusée si la grille a été retouchée, puis acceptée ; devis revenu
        $this->db->executeStatement('UPDATE rental_pack SET day_rate = 11000 WHERE id = ?', [$this->pack]);
        $this->adminRequest('POST', '/api/gestion/locations/grilles/annuler', ['changeId' => $change['id']]);
        $this->assertSame([409, 'GRID_EDITED_SINCE'], [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true)['error']]);
        $this->db->executeStatement('UPDATE rental_pack SET day_rate = 10500 WHERE id = ?', [$this->pack]);
        $undone = $this->send('POST', '/api/gestion/locations/grilles/annuler', ['changeId' => $change['id']], 200);
        $this->assertNotNull($undone['undoneAt']);
        $this->assertSame($rates0, $rates());
        $this->assertSame($quote0, $quote(), 'devis revenu à l\'identique');
        $this->adminRequest('POST', '/api/gestion/locations/grilles/annuler', ['changeId' => $change['id']]);
        $this->assertSame([409, 'ALREADY_UNDONE'], [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true)['error']]);

        // Tarifs saisis, délai d'annulation dépassé, rien à changer
        $p2 = $this->send('POST', '/api/gestion/locations/grilles/apercu', ['rentalPackId' => $this->pack, 'rates' => ['day' => 9900, 'week' => null]], 200);
        $this->assertSame([9900, null], [$p2['after']['day'], $p2['after']['week']]);
        $c2 = $this->send('POST', '/api/gestion/locations/grilles/appliquer', ['rentalPackId' => $this->pack, 'rates' => ['day' => 9900, 'week' => null], 'allowShared' => true, 'previewHash' => $p2['previewHash']], 201);
        $this->db->executeStatement("UPDATE gestion_rental_grid_change SET created_at = NOW() - INTERVAL '25 hours' WHERE id = ?", [$c2['id']]);
        $this->adminRequest('POST', '/api/gestion/locations/grilles/annuler', ['changeId' => $c2['id']]);
        $this->assertSame([409, 'UNDO_EXPIRED'], [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true)['error']]);
        $same = $this->send('POST', '/api/gestion/locations/grilles/apercu', ['rentalPackId' => $this->pack, 'rates' => ['day' => 9900]], 200);
        $this->adminRequest('POST', '/api/gestion/locations/grilles/appliquer', ['rentalPackId' => $this->pack, 'rates' => ['day' => 9900], 'allowShared' => true, 'previewHash' => $same['previewHash']]);
        $this->assertSame([422, 'NOTHING_TO_CHANGE'], [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true)['error']]);
        $this->assertSame([$c2['id'], $change['id']], array_column($this->send('GET', '/api/gestion/locations/grilles/modifications', null, 200), 'id'));

        // Validations
        foreach ([[['percent' => 10], ['rentalPackId' => 'REQUIRED']], [['rentalPackId' => $this->pack, 'percent' => 10, 'rates' => ['day' => 1]], ['rates' => 'RATES_OR_PERCENT']],
            [['rentalPackId' => $this->pack, 'rates' => ['jour' => 1]], ['rates.jour' => 'UNKNOWN_RATE']], [['rentalPackId' => $this->pack, 'rates' => ['day' => -5]], ['rates.day' => 'MUST_BE_POSITIVE_INTEGER_CENTS']],
            [['rentalPackId' => $this->pack, 'percent' => 0], ['percent' => 'OUT_OF_RANGE']], [['rentalPackId' => $this->pack, 'percent' => 10, 'rounding' => 'x'], ['rounding' => 'INVALID_ROUNDING']]] as [$b, $fields]) {
            $this->adminRequest('POST', '/api/gestion/locations/grilles/apercu', $b);
            $this->assertSame([422, $fields], [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true)['fields'] ?? null], json_encode($b));
        }
        $this->adminRequest('POST', '/api/gestion/locations/grilles/apercu', ['rentalPackId' => 999999999, 'percent' => 10]);
        $this->assertSame([404, 'PACK_NOT_FOUND'], [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true)['error']]);
    }

    // ----- helpers -----

    private function send(string $method, string $path, ?array $body, int $expected): array
    {
        $this->adminRequest($method, $path, $body);
        $this->assertSame($expected, $this->client->getResponse()->getStatusCode(), $path . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 500));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function adminRequest(string $method, string $path, ?array $body = null): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->adminToken], $body !== null ? json_encode($body) : null);
    }

    private function json(Response $response, int $expected = 200): array
    {
        $this->assertSame($expected, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent(), true);
    }

    private function request(string $method, string $path, ?array $body = null): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null,
        ]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function user(array $roles): User
    {
        $email = 'grille-location-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Grille')->setLastname('Location')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $user;
    }

    private function loginPos(array $roles = ['ROLE_USER_POS']): User
    {
        $user = $this->user($roles);
        $this->token = null;
        $login = $this->request('POST', '/api/login', ['username' => $user->getEmail(), 'password' => self::PASSWORD, 'platform' => 'pos']);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];

        return $user;
    }
}
