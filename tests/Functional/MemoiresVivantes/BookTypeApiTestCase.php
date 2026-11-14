<?php

namespace App\Tests\Functional\MemoiresVivantes;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeAnthropicService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base des tests du contrat API « Types de livre » : requêtes faites comme le front
 * (préfixe /api/memoires, ?locale=fr partout, X-Tenant-Host, X-XSRF-TOKEN sur les écritures)
 * contre le tenant fictif « mvtest » (voir tests/bootstrap.php).
 */
abstract class BookTypeApiTestCase extends WebTestCase
{
    protected const ADMIN_EMAIL = 'admin@arkanoa-media.com';
    protected const CLIENT_EMAIL = 'client-non-admin@example.invalid';
    /** Super administrateur (jeton émis directement : le code OTP ne s'applique qu'à /api/login) ; créé à la demande dans la copie de base */
    protected const SUPER_EMAIL = 'super-admin-mvtest@example.invalid';

    protected KernelBrowser $client;
    private static ?\PDO $pdo = null;
    private static array $tokens = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeAnthropicService::reset();
    }

    /**
     * Appel d'API comme le fait le front.
     *
     * @param 'admin'|'client'|null $as utilisateur authentifié (JWT en en-tête), null = anonyme
     * @return array{0: int, 1: mixed} [code HTTP, corps JSON décodé]
     */
    protected function api(string $method, string $path, ?array $body = null, ?string $as = 'admin', string $prefix = '/api/memoires'): array
    {
        // Depuis le 14/11/2026, seule l'équipe crée un livre WEB (Mémoires Vivantes web est son outil). Les tests écrits avant, où le
        // client de test crée « son » livre web, gardent leur sens pour les livres d'AVANT, que le code sert toujours à leur propriétaire :
        // le livre est créé par l'administrateur puis rendu au client en base, et la réponse est la lecture du client.
        if ($method === 'POST' && $path === '/books' && $as === 'client' && $prefix === '/api/memoires') {
            return $this->legacyClientWebBook($body ?? []);
        }
        $uri = $prefix . $path . (str_contains($path, '?') ? '&' : '?') . 'locale=fr';
        $server = [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ];
        if ($method !== 'GET') {
            $server['HTTP_X_XSRF_TOKEN'] = 'jeton-xsrf-de-test';
        }
        if ($as !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->tokenFor($as);
        }

        $this->client->request($method, $uri, [], [], $server, $body !== null ? json_encode($body) : null);
        $response = $this->client->getResponse();

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    }

    /**
     * Livre web appartenant au client de test, comme un livre créé avant le 14/11/2026 (voir api()).
     *
     * @return array{0: int, 1: mixed} la réponse de la création si elle est refusée, sinon [201, le livre lu par le client]
     */
    protected function legacyClientWebBook(array $body): array
    {
        [$status, $created] = $this->api('POST', '/books', $body, 'admin');
        if ($status !== 201) {
            return [$status, $created];
        }
        $this->tokenFor('client'); // le compte existe
        $st = self::db()->prepare('UPDATE mv_book SET user_id = (SELECT id FROM "user" WHERE email = ?), biographer_id = NULL WHERE id = ?');
        $st->execute([self::CLIENT_EMAIL, $created['id']]);
        static::getContainer()->get(\App\Services\TenantEntityManagerProvider::class)->getEntityManager()->clear();
        [$read, $book] = $this->api('GET', '/books/' . $created['id'], null, 'client');

        return [$read === 200 ? 201 : $read, $book];
    }

    /**
     * Tous les comptes de GET /admin/users, page par page (pagination S8 : 200 au plus par page, total dans X-Total-Count) ; la base de
     * test partagée en accumule plusieurs centaines au fil de la suite.
     */
    protected function allAdminUsers(string $as = 'admin'): array
    {
        $all = [];
        for ($page = 1; $page <= 50; $page++) {
            [$status, $rows] = $this->api('GET', '/admin/users?page=' . $page . '&limit=200', null, $as);
            $this->assertSame(200, $status);
            $all = array_merge($all, $rows);
            if (count($all) >= (int) $this->client->getResponse()->headers->get('X-Total-Count') || $rows === []) {
                break;
            }
        }

        return $all;
    }

    /** Détail d'un type par son code */
    protected function typeByCode(string $code): array
    {
        [$status, $list] = $this->api('GET', '/admin/book-types');
        $this->assertSame(200, $status);
        foreach ($list as $row) {
            if ($row['code'] === $code) {
                [, $detail] = $this->api('GET', '/admin/book-types/' . $row['id']);
                return $detail['bookType'];
            }
        }
        $this->fail("Type « $code » introuvable");
    }

    protected function chapter(array $bookType, string $code): array
    {
        foreach ($bookType['chapters'] as $chapter) {
            if ($chapter['code'] === $code) {
                return $chapter;
            }
        }
        $this->fail("Chapitre « $code » introuvable");
    }

    protected function role(array $bookType, string $code): array
    {
        foreach ($bookType['roles'] as $role) {
            if ($role['code'] === $code) {
                return $role;
            }
        }
        $this->fail("Rôle « $code » introuvable");
    }

    /** Toutes les questions d'un type, à plat */
    protected function questions(array $bookType): array
    {
        return array_merge(...array_map(fn ($c) => $c['questions'], $bookType['chapters']));
    }

    protected function question(array $bookType, int $id): array
    {
        foreach ($this->questions($bookType) as $question) {
            if ($question['id'] === $id) {
                return $question;
            }
        }
        $this->fail("Question $id introuvable");
    }

    /** Accès direct à la base du tenant de test, pour les vérifications et fixtures */
    protected static function db(): \PDO
    {
        if (self::$pdo === null) {
            $u = parse_url(getenv('DATABASE_URL'));
            self::$pdo = new \PDO(
                sprintf('pgsql:host=%s;port=%d;dbname=%s', $u['host'], $u['port'] ?? 5432, MV_TEST_TENANT_DB),
                urldecode($u['user']),
                urldecode($u['pass']),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]
            );
        }
        return self::$pdo;
    }

    /** Jeton JWT d'un admin ou d'un client non admin du tenant de test (créé au besoin) */
    protected function tokenFor(string $who): string
    {
        if (isset(self::$tokens[$who])) {
            return self::$tokens[$who];
        }

        $container = static::getContainer();
        $container->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $container->get(TenantEntityManagerProvider::class)->getEntityManager();

        $email = match ($who) { 'admin' => self::ADMIN_EMAIL, 'super' => self::SUPER_EMAIL, default => self::CLIENT_EMAIL };
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $this->assertContains($who, ['client', 'super'], 'L\'admin de test doit exister dans la copie de base');
            $user = (new User())
                ->setEmail($email)
                ->setUsername($email)
                ->setFirstname($who === 'super' ? 'Super' : 'Client')
                ->setLastname('Test')
                ->setRoles($who === 'super' ? ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'] : ['ROLE_USER_INTERNET'])
                ->setPassword('inutilisable')
                ->setIsVerified(true);
            $em->persist($user);
            $em->flush();
        }

        return self::$tokens[$who] = $container->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }

    protected static function uniqueCode(string $prefix): string
    {
        return $prefix . '_' . substr(bin2hex(random_bytes(4)), 0, 6);
    }
}
