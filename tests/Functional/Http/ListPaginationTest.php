<?php

namespace App\Tests\Functional\Http;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot PERF-PAGINATION (18/11/2026, contrat validé par le chef) : `GET /api/contacts` et `GET /api/orders` acceptent `?page=&limit=`, FACULTATIFS.
 * Sans page : la réponse d'avant (tableau entier), plus `X-Total-Count`. Avec : la même forme limitée à la page, `X-Total-Count`, `X-Page`,
 * `X-Limit` ; ordre stable du plus récent au plus ancien ; bornes ramenées sans erreur (limit 1 à 200) ; droits inchangés. Les trois en-têtes
 * sont exposés au navigateur par nginx (`Access-Control-Expose-Headers`).
 */
class ListPaginationTest extends WebTestCase
{
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private string $tag;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        $this->tag = bin2hex(random_bytes(3));
    }

    protected function tearDown(): void
    {
        $this->db->executeStatement("DELETE FROM \"order\" WHERE reference LIKE 'PAGE-%'");
        $this->db->executeStatement("DELETE FROM contact WHERE email LIKE 'page-%@example.invalid'");
        $this->db->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'page-%@example.invalid'");
        parent::tearDown();
    }

    public function testContactsArePagedOnlyWhenAskedAndTheWholeListIsUnchanged(): void
    {
        foreach ([3, 2, 1] as $hoursAgo) {
            $this->db->executeStatement("INSERT INTO contact (id, name, email, subject, content, created_at) VALUES (nextval('contact_id_seq'), ?, ?, 'sujet', 'message', ?)",
                ["Contact $hoursAgo", "page-$hoursAgo-{$this->tag}@example.invalid", gmdate('Y-m-d H:i:s', time() + 86400 - 3600 * $hoursAgo)]);
        }
        $admin = $this->token(['ROLE_ADMIN']);
        $whole = $this->call('/api/contacts', $admin);
        $all = json_decode((string) $whole->getContent(), true);
        $this->assertTrue(array_is_list($all), 'sans page : un tableau, comme avant');
        $this->assertSame((string) count($all), $whole->headers->get('X-Total-Count'));
        $this->assertNull($whole->headers->get('X-Page'));

        $first = $this->call('/api/contacts?page=1&limit=2', $admin);
        $second = $this->call('/api/contacts?page=2&limit=2', $admin);
        $one = json_decode((string) $first->getContent(), true);
        $two = json_decode((string) $second->getContent(), true);
        $this->assertCount(2, $one);
        $this->assertSame([(string) count($all), '1', '2'], [$first->headers->get('X-Total-Count'), $first->headers->get('X-Page'), $first->headers->get('X-Limit')]);
        $this->assertSame([], array_intersect(array_column($one, 'id'), array_column($two, 'id')), 'deux pages ne se recoupent pas');
        $this->assertSame(['Contact 1', 'Contact 2'], array_column($one, 'name'), 'le plus récent d\'abord');
        $this->assertSame(array_keys($all[0]), array_keys($one[0]), 'mêmes objets que la liste entière');

        // bornes ramenées sans erreur
        $this->assertSame(['200', '1'], [$this->call('/api/contacts?page=0&limit=999', $admin)->headers->get('X-Limit'), $this->call('/api/contacts?page=0&limit=999', $admin)->headers->get('X-Page')]);
        $this->assertSame('1', $this->call('/api/contacts?page=1&limit=-5', $admin)->headers->get('X-Limit'));
        // droits inchangés
        $this->assertSame(403, $this->call('/api/contacts?page=1', $this->token(['ROLE_USER_INTERNET']))->getStatusCode());
    }

    public function testOrdersOfASourceArePagedNewestFirstAndRightsAreUnchanged(): void
    {
        $owner = (int) $this->db->fetchOne("SELECT id FROM \"user\" WHERE email = 'admin@arkanoa-media.com'");
        $ids = [];
        foreach ([1, 2, 3] as $daysAhead) {
            // dans le futur : en tête de la liste, quoi que d'autres essais aient laissé
            $ids[$daysAhead] = (int) $this->db->fetchOne("INSERT INTO \"order\" (id, reference, order_date, order_type_id, status_id, order_source_id, user_id_id, total_amount, gift_card_applied, shipping_cost) VALUES (nextval('order_id_seq'), ?, ?, 1, 3, 1, ?, 1000, 0, 0) RETURNING id",
                ["PAGE-$daysAhead-{$this->tag}", gmdate('Y-m-d H:i:s', time() + 86400 * $daysAhead), $owner]);
        }
        $admin = $this->token(['ROLE_ADMIN']);
        $whole = $this->call('/api/orders?orderSource=1', $admin);
        $all = json_decode((string) $whole->getContent(), true);
        $this->assertSame(200, $whole->getStatusCode(), (string) $whole->getContent());
        $this->assertTrue(array_is_list($all));
        $this->assertSame((string) count($all), $whole->headers->get('X-Total-Count'));
        $this->assertNull($whole->headers->get('X-Page'), 'sans page : la réponse d\'avant');

        $one = json_decode((string) $this->call('/api/orders?orderSource=1&page=1&limit=2', $admin)->getContent(), true);
        $twoResponse = $this->call('/api/orders?orderSource=1&page=2&limit=2', $admin);
        $two = json_decode((string) $twoResponse->getContent(), true);
        $this->assertSame([$ids[3], $ids[2]], array_column($one, 'id'), 'la plus récente d\'abord');
        $this->assertSame($ids[1], $two[0]['id'] ?? null);
        $this->assertSame([(string) count($all), '2', '2'], [$twoResponse->headers->get('X-Total-Count'), $twoResponse->headers->get('X-Page'), $twoResponse->headers->get('X-Limit')]);
        $this->assertSame(array_keys($all[0]), array_keys($one[0]), 'mêmes objets que la liste entière');

        // droits : la caisse ne lit que ses ventes, un client rien ; la source reste obligatoire
        $this->assertSame(403, $this->call('/api/orders?orderSource=1&page=1', $this->token(['ROLE_USER_POS']))->getStatusCode());
        $this->assertSame(403, $this->call('/api/orders?orderSource=1&page=1', $this->token(['ROLE_USER_INTERNET']))->getStatusCode());
        $this->assertSame(400, $this->call('/api/orders?page=1', $admin)->getStatusCode());
    }

    public function testThePagingHeadersAreExposedToTheBrowserByNginx(): void
    {
        $conf = (string) file_get_contents(dirname(__DIR__, 3) . '/docker/nginx/default.conf');
        $this->assertSame(1, preg_match("/add_header 'Access-Control-Expose-Headers' '([^']*)' always;/", $conf, $m), 'une règle d\'exposition des en-têtes');
        foreach (['X-Total-Count', 'X-Page', 'X-Limit'] as $header) {
            $this->assertContains($header, array_map('trim', explode(',', $m[1])), $header);
        }
    }

    private function call(string $path, string $token): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        return $this->client->getResponse();
    }

    /** @param list<string> $roles */
    private function token(array $roles): string
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $email = 'page-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Page')->setLastname('Essai')->setRoles($roles)->setPassword('inutilisable')->setIsVerified(true);
        $em->persist($user);
        $em->flush();

        return static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }
}
