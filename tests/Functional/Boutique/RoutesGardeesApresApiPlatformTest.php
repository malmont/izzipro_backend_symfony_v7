<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\Product;
use App\Entity\User;
use App\Entity\VehicleProduct;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\SqlRecorderMiddleware;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Contrat des routes gardées au retrait d'API Platform (lot APIP-RETRAIT, voie 2 décidée par le propriétaire le 20/11/2026).
 *
 * Seuls trois appels des fronts passaient par API Platform et sont gardés, au même chemin et au même format :
 *  - la boutique : GET /api/vehicle_products?page=&locale= et GET /api/vehicle_products/{id} (axios : tableau JSON simple) ;
 *  - l'application de gestion (MAUI) : DELETE /api/products/{id} (204 sans corps).
 * Tout le reste est fermé : écritures sur /api/vehicles et /api/vehicle_products, documentation et contextes JSON-LD.
 *
 * La forme des lectures est figée dans tests/Fixtures/contrats/vehicle-products.json, CAPTURÉE sur le code avec API Platform
 * (CONTRAT_CAPTURE=1), puis comparée après le retrait : clés et types de chaque élément, contenu exact d'un véhicule créé
 * par l'essai, pagination (24 par page, page 2), réponse d'erreur, nombre de requêtes SQL et temps de réponse.
 */
class RoutesGardeesApresApiPlatformTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private const FIXTURE = __DIR__ . '/../../Fixtures/contrats/vehicle-products.json';
    private const AXIOS = 'application/json, text/plain, */*';
    private const CODE = 'CONTRAT-VP-';

    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-contrat@example.invalid', false, false);
        $this->ensureVehicleProducts(30);
    }

    /** La base de test est partagée : les véhicules de l'essai, en ligne, fausseraient les listes d'articles des autres essais */
    protected function tearDown(): void
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $em->clear();
        foreach ($em->getRepository(VehicleProduct::class)->createQueryBuilder('vp')->andWhere('vp.code LIKE :c')
            ->setParameter('c', self::CODE . '%')->getQuery()->getResult() as $vehicle) {
            $em->remove($vehicle);
        }
        $em->flush();
        parent::tearDown();
    }

    public function testVehicleProductReadsKeepTheirShapeForEveryAcceptHeader(): void
    {
        $first = $this->vehicleId(1);
        $apiPlatform = class_exists(\ApiPlatform\Metadata\ApiResource::class);
        // ld+json et « sans Accept » : JSON-LD tant qu'API Platform est là (aucun front ne les envoie) ; tableau simple après
        foreach (['ld+json' => 'application/ld+json', 'sans' => null] as $label => $accept) {
            $r = $this->get('/api/vehicle_products?page=1&locale=fr', $accept);
            $this->assertSame(200, $r->getStatusCode(), "liste ($label)");
            $body = json_decode($r->getContent(), true);
            if ($apiPlatform) {
                $this->assertArrayHasKey('hydra:member', $body, "liste ($label) avec API Platform : JSON-LD");
            } else {
                $this->assertTrue(array_is_list($body), "liste ($label) après le retrait : tableau simple");
                $this->assertSame(json_decode($this->get('/api/vehicle_products?page=1&locale=fr', self::AXIOS)->getContent(), true), $body, "liste ($label) : la même que pour axios");
            }
        }
        // Mesuré à la capture (20/11/2026) : avec l'en-tête d'axios, API Platform 3.4 rendait du JSON-LD (`hydra:member`, `@id`,
        // `@type`, `@context`) ; seul `Accept: application/json` donnait le tableau simple. La cible après le retrait est ce
        // tableau simple, pour TOUS les en-têtes (la boutique lit un tableau, `data` ou `hydra:member`). Les deux réponses
        // d'avant sont gardées dans la capture : `reads` (application/json, la cible) et `readsAxiosAvant` (pour mémoire).
        $observed = [];
        foreach (['axios' => self::AXIOS, 'json' => 'application/json'] as $label => $accept) {
            $list = $this->get('/api/vehicle_products?page=1&locale=fr', $accept);
            $this->assertSame(200, $list->getStatusCode(), "liste ($label)");
            $body = json_decode($list->getContent(), true);
            $members = array_is_list($body) ? $body : ($body['hydra:member'] ?? []);
            $this->assertCount(24, $members, "liste ($label) : 24 par page");
            $item = $this->get("/api/vehicle_products/$first?locale=fr", $accept);
            $this->assertSame(200, $item->getStatusCode(), "fiche ($label)");
            $observed[$label] = [
                'contentType' => $this->mediaType($list), 'itemContentType' => $this->mediaType($item), 'list' => array_is_list($body),
                'listShape' => $this->shape($members[0]), 'own' => $this->own($members), 'item' => $this->normalize(json_decode($item->getContent(), true)),
            ];
        }

        // Pagination (format cible) : page 2, ordre croissant des identifiants, page vide au-delà, locale sans effet
        $page1 = json_decode($this->get('/api/vehicle_products?page=1&locale=fr', 'application/json')->getContent(), true);
        $page2 = json_decode($this->get('/api/vehicle_products?page=2&locale=en', 'application/json')->getContent(), true);
        $ids = array_merge(array_column($page1, 'id'), array_column($page2, 'id'));
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids, 'ordre croissant des identifiants sur les deux pages');
        $this->assertSame(count($ids), count(array_unique($ids)), 'aucun doublon entre les pages');
        $this->assertNotEmpty($page2, 'page 2 : la suite de la liste (32 véhicules au moins)');
        $this->assertSame([], json_decode($this->get('/api/vehicle_products?page=999&locale=fr', 'application/json')->getContent(), true), 'page au-delà : tableau vide');
        if (!$apiPlatform) {
            // relecture 2 : bornes démesurées, jamais de 500 public (un 5xx nourrit la boîte des incidents) ; mesuré : 404 aussi avec \d+
            $this->assertSame([], json_decode($this->get('/api/vehicle_products?page=99999999999999999999', 'application/json')->getContent(), true), 'page démesurée : tableau vide');
            $this->assertSame(404, $this->get('/api/vehicle_products/99999999999999999999', 'application/json')->getStatusCode(), 'identifiant démesuré : 404');
            $this->assertSame(404, $this->get('/api/vehicle_products/0', 'application/json')->getStatusCode(), 'identifiant 0 : 404');
        }
        $this->assertNotNull($observed['json']['own'] ?? null, 'le véhicule CONTRAT-VP-01 est dans la page 1 : son contenu exact est comparé');

        // Fiche absente : statut et corps
        $missing = $this->get('/api/vehicle_products/999999999?locale=fr', 'application/json');
        $observed['json']['missing'] = ['status' => $missing->getStatusCode(), 'body' => $this->normalize(json_decode($missing->getContent(), true))];

        if (getenv('CONTRAT_CAPTURE') === '1') {
            $this->writeFixture('readsAxiosAvant', $observed['axios']);
            $this->compareOrCapture('reads', $observed['json']);
        }
        // Après le retrait : axios et application/json rendent exactement le tableau simple d'avant
        $missingNow = $observed['json']['missing'];
        unset($observed['json']['missing']);
        $this->assertSame($observed['json'], $observed['axios'], 'axios et application/json : la même réponse');
        $observed['json']['missing'] = $missingNow;
        $this->compareOrCapture('reads', $observed['json']);
    }

    private function mediaType(Response $r): string
    {
        return strtolower(trim(explode(';', (string) $r->headers->get('Content-Type'))[0]));
    }

    public function testListAndItemAreNotSlowerAndHaveNoNPlusOne(): void
    {
        $first = $this->vehicleId(1);
        $measure = function (string $path): array {
            for ($i = 0; $i < 3; $i++) {
                $this->get($path, self::AXIOS); // chauffe
            }
            $times = [];
            $queries = 0;
            for ($i = 0; $i < 15; $i++) {
                SqlRecorderMiddleware::start();
                $t = hrtime(true);
                $r = $this->get($path, self::AXIOS);
                $times[] = (hrtime(true) - $t) / 1e6;
                $queries = count(SqlRecorderMiddleware::stop());
                $this->assertSame(200, $r->getStatusCode(), $path);
            }
            sort($times);

            return ['ms' => round($times[7], 2), 'queries' => $queries];
        };
        $now = [
            'list' => $measure('/api/vehicle_products?page=1&locale=fr'),
            'page2' => $measure('/api/vehicle_products?page=2&locale=fr'),
            'item' => $measure("/api/vehicle_products/$first?locale=fr"),
        ];
        // Pas de N+1 : une page pleine (24) ne coûte pas plus de requêtes qu'une page presque vide. Exigé APRÈS le retrait :
        // API Platform en avait un (configuration de réservation chargée une par une : 26 requêtes pour 24 éléments, 10 pour 8)
        if (!class_exists(\ApiPlatform\Metadata\ApiResource::class)) {
            $this->assertSame($now['page2']['queries'], $now['list']['queries'], 'même nombre de requêtes pour 24 éléments et pour quelques-uns');
        }

        if (getenv('CONTRAT_CAPTURE') === '1') {
            $this->writeFixture('perf', $now);
            $this->markTestIncomplete('Capture des mesures : ' . json_encode($now));
        }
        $before = $this->fixture()['perf'] ?? $this->fail('Mesures de référence absentes : capturer avec CONTRAT_CAPTURE=1 sur le code avec API Platform');
        fwrite(STDERR, sprintf("\n[Contrat] avant %s | après %s\n", json_encode($before), json_encode($now)));
        foreach (['list', 'item'] as $k) {
            $this->assertLessThanOrEqual($before[$k]['queries'] + ($k === 'list' ? 1 : 0), $now[$k]['queries'], "$k : au plus une requête SQL de plus que la référence");
            // Le temps ne se compare que mesuré seul (CONTRAT_PERF=1) : sous la charge d'une suite complète, il échouerait à tort
            if (getenv('CONTRAT_PERF') === '1') {
                $this->assertLessThanOrEqual($before[$k]['ms'] * 1.25 + 2, $now[$k]['ms'], "$k : pas plus lent que la référence ({$before[$k]['ms']} ms)");
            }
        }
    }

    public function testWritesOnVehiclesAreRefusedAndNothingIsWritten(): void
    {
        $vp = $this->vehicleId(1);
        $count = fn () => (int) $this->db->fetchOne('SELECT (SELECT COUNT(*) FROM product) + (SELECT COUNT(*) FROM vehicle_product) + (SELECT COUNT(*) FROM vehicle)');
        $snapshot = fn () => $this->db->fetchAssociative('SELECT p.name, p.price, p.code, v.brand FROM product p JOIN vehicle_product v ON v.id = p.id WHERE p.id = ?', [$vp]);
        $vehicle = (int) ($this->db->fetchOne('SELECT MIN(id) FROM vehicle') ?: 1);
        $body = ['name' => 'Écrit par un intrus', 'title' => 'Écrit par un intrus', 'price' => 1, 'brand' => 'Intrus', 'year' => 1999];
        $before = [$count(), $snapshot()];
        // Avant le retrait, l'écriture anonyme hors pare-feu JWT était refusée par une redirection (302) ; après : la route n'existe plus
        $refused = class_exists(\ApiPlatform\Metadata\ApiResource::class) ? [302, 401, 403, 404, 405] : [404, 405];
        foreach ([null, 'client'] as $who) {
            $this->token = null;
            if ($who === 'client') {
                $this->loginAs(['ROLE_USER_INTERNET'], 'mobile');
            }
            foreach ([['POST', '/api/vehicle_products'], ['PUT', "/api/vehicle_products/$vp"], ['PATCH', "/api/vehicle_products/$vp"], ['DELETE', "/api/vehicle_products/$vp"],
                      ['POST', '/api/vehicles'], ['PUT', "/api/vehicles/$vehicle"], ['PATCH', "/api/vehicles/$vehicle"], ['DELETE', "/api/vehicles/$vehicle"]] as [$method, $path]) {
                $r = $this->send($method, $path, $body);
                $this->assertContains($r->getStatusCode(), $refused, sprintf('%s %s (%s) refusé : %d %s', $method, $path, $who ?? 'anonyme', $r->getStatusCode(), substr((string) $r->getContent(), 0, 200)));
            }
        }
        $this->assertSame($before, [$count(), $snapshot()], 'rien n\'a été écrit');
    }

    public function testDeleteProductKeepsTheMauiContract(): void
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $victim = $this->newVehicleProduct(self::CODE . 'SUPPR-' . bin2hex(random_bytes(3)));
        $em->persist($victim);
        $em->flush();
        $id = $victim->getId();
        $em->clear();
        $observed = [];

        $this->token = null;
        $r = $this->send('DELETE', "/api/products/$id");
        $observed['anonyme'] = [$r->getStatusCode(), $this->normalize(json_decode((string) $r->getContent(), true))];
        $this->loginAs(['ROLE_USER_INTERNET'], 'mobile');
        $r = $this->send('DELETE', "/api/products/$id");
        $observed['client'] = [$r->getStatusCode(), $this->normalize(json_decode((string) $r->getContent(), true))];
        $this->assertNotFalse($this->db->fetchOne('SELECT id FROM product WHERE id = ?', [$id]), 'refusé : l\'article est toujours là');

        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']);
        $r = $this->send('DELETE', '/api/products/999999999');
        $observed['absent'] = [$r->getStatusCode(), $this->normalize(json_decode((string) $r->getContent(), true))];
        $r = $this->send('DELETE', "/api/products/$id");
        $this->assertSame(204, $r->getStatusCode(), (string) $r->getContent());
        $this->assertSame('', (string) $r->getContent(), '204 sans corps');
        $this->assertFalse($this->db->fetchOne('SELECT id FROM product WHERE id = ?', [$id]), 'supprimé');

        $this->compareOrCapture('delete', $observed);
    }

    public function testDocumentationAndJsonLdContextsAreClosed(): void
    {
        $apiPlatform = class_exists(\ApiPlatform\Metadata\ApiResource::class);
        foreach ([null, 'client', 'admin'] as $who) {
            $this->token = null;
            if ($who === 'client') {
                $this->loginAs(['ROLE_USER_INTERNET'], 'mobile');
            } elseif ($who === 'admin') {
                $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']);
            }
            foreach (['/api', '/api/index.jsonld', '/api/docs', '/api/docs.jsonopenapi', '/api/docs?ui=scalar', '/api/contexts/Product', '/api/contexts/VehicleProduct.jsonld'] as $path) {
                $status = $this->get($path, 'text/html')->getStatusCode();
                if ($apiPlatform) {
                    // avant le retrait : fermé aux anonymes et aux clients (COST-1)
                    $who === 'admin' || $this->assertContains($status, [401, 403], "$path ($who) avant le retrait");
                } else {
                    $this->assertSame(404, $status, "$path (" . ($who ?? 'anonyme') . ') : la route n\'existe plus');
                }
            }
        }
    }

    // ---------------------------------------------------------------------------------------------------------------- outils

    private function ensureVehicleProducts(int $n): void
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $have = (int) $this->db->fetchOne("SELECT COUNT(*) FROM product WHERE code LIKE ?", [self::CODE . '%']);
        for ($i = $have + 1; $i <= $n; $i++) {
            $em->persist($this->newVehicleProduct(sprintf('%s%02d', self::CODE, $i)));
        }
        $em->flush();
    }

    private function newVehicleProduct(string $code): VehicleProduct
    {
        $p = new VehicleProduct();
        $p->setName('Véhicule ' . $code)->setDescription('Description ' . $code)->setMoreinformations('')->setPrice(1234500)->setCode($code)
            ->setSlug(strtolower($code))->setTags('contrat')->setIsWeb(true)->setIsPos(false)->setIsPreOrder(false)->setIsAccessory(false)
            ->setIsbestseller(false)->setIsnewarrival(true)->setIsfeatured(false)->setIsspecialoffer(false)
            ->setSaleEnabled(true)->setRentalEnabled(false)->setSubscriptionEnabled(false)->setCustomizable(false);
        $p->setBrand('Marque')->setModel('Modèle')->setYear(2024)->setVehicleCondition('neuf')->setColor('rouge')
            ->setGasType('essence')->setTransmission('auto')->setEnginePower('150 ch')->setHoursOrMileage(1200);

        return $p;
    }

    private function vehicleId(int $n): int
    {
        return (int) $this->db->fetchOne('SELECT id FROM product WHERE code = ?', [sprintf('%s%02d', self::CODE, $n)]);
    }

    /** Clés et types, récursivement : la forme d'un élément, indépendante des valeurs */
    private function shape(mixed $v): mixed
    {
        if (is_array($v)) {
            if ($v !== [] && array_is_list($v)) {
                return ['[]' => $this->shape($v[0])];
            }
            $out = [];
            foreach ($v as $k => $x) {
                $out[$k] = $this->shape($x);
            }
            ksort($out);

            return $out;
        }

        return get_debug_type($v);
    }

    /** Contenu exact du premier véhicule créé par l'essai (s'il est dans la page) */
    private function own(array $list): ?array
    {
        foreach ($list as $e) {
            if (($e['code'] ?? null) === self::CODE . '01' || ($e['slug'] ?? null) === strtolower(self::CODE . '01')) {
                return $this->normalize($e);
            }
        }

        return null;
    }

    /** Valeurs qui changent d'un passage à l'autre remplacées : identifiants, dates */
    private function normalize(mixed $v): mixed
    {
        if (!is_array($v)) {
            if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $v)) {
                return '<date>';
            }

            return is_string($v) ? preg_replace('#/\d+#', '/<id>', $v) : $v;
        }
        $out = [];
        foreach ($v as $k => $x) {
            $out[$k] = in_array($k, ['id', '@id'], true) && !is_array($x) ? '<id>' : $this->normalize($x);
        }

        return $out;
    }

    private function compareOrCapture(string $key, array $observed): void
    {
        if (getenv('CONTRAT_CAPTURE') === '1') {
            $this->writeFixture($key, $observed);
            $this->markTestIncomplete("Capture de « $key » écrite");
        }
        $this->assertSame($this->fixture()[$key] ?? null, json_decode(json_encode($observed), true), "« $key » identique à la capture faite avec API Platform");
    }

    private function fixture(): array
    {
        return is_file(self::FIXTURE) ? json_decode((string) file_get_contents(self::FIXTURE), true) : [];
    }

    private function writeFixture(string $key, array $value): void
    {
        $all = $this->fixture();
        $all[$key] = $value;
        ksort($all);
        @mkdir(dirname(self::FIXTURE), 0777, true);
        file_put_contents(self::FIXTURE, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    private function get(string $path, ?string $accept): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter(['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => $accept, 'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null]));

        return $this->client->getResponse();
    }

    private function send(string $method, string $path, ?array $body = null): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter(['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => $method === 'PATCH' ? 'application/merge-patch+json' : 'application/json', 'HTTP_ACCEPT' => self::AXIOS, 'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function loginAs(array $roles, string $platform = 'pos'): User
    {
        $email = 'contrat-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Contrat')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->token = null;
        $login = $this->send('POST', '/api/login', ['username' => $email, 'password' => self::PASSWORD, 'platform' => $platform]);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];

        return $user;
    }
}
