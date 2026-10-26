<?php

namespace App\Tests\Functional\MemoiresVivantes;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Console d'administration : populations et utilisateurs de l'application mobile (16/10/2026). Origine du compte à
 * l'inscription, dernière connexion, population calculée, liste paginée et détail des utilisateurs mobile.
 */
class MobileUserAdminTest extends BookTypeApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->api('PUT', '/admin/ai-quotas', ['transcriptionsPerDay' => 40, 'improvementsPerDay' => 60, 'maxAudioSeconds' => 300, 'applyToWebBooks' => false, 'timezone' => 'America/Martinique']);
    }

    public function testSignupChannelAndLastLoginAreRecorded(): void
    {
        $email = 'inscrit-mobile-' . bin2hex(random_bytes(3)) . '@example.com';
        $status = $this->register($email, ['app' => 'livingmemory']);
        $this->assertContains($status, [200, 201], 'inscription depuis l\'application');
        $row = self::db()->query("SELECT signup_channel, created_at, last_login_at, roles::text AS roles FROM \"user\" WHERE email = '$email'")->fetch();
        $this->assertSame(['mobile', null], [$row['signup_channel'], $row['last_login_at']]);
        $this->assertNotNull($row['created_at']);
        $this->assertStringContainsString('ROLE_USER_INTERNET', $row['roles']);

        $web = 'inscrit-web-' . bin2hex(random_bytes(3)) . '@example.com';
        $this->register($web, []);
        $this->assertSame('web_register', self::db()->query("SELECT signup_channel FROM \"user\" WHERE email = '$web'")->fetchColumn(), 'sans app : inscription web');

        $invitedEmail = 'invite-' . bin2hex(random_bytes(3)) . '@example.com';
        [$status, $invited] = $this->api('POST', '/admin/users', ['email' => $invitedEmail, 'firstName' => 'Inv', 'lastName' => 'Ité', 'role' => 'ROLE_USER']);
        $this->assertContains($status, [200, 201], json_encode($invited));
        $this->assertSame('web_invite', self::db()->query("SELECT signup_channel FROM \"user\" WHERE email = '$invitedEmail'")->fetchColumn(), 'invitation par un biographe');

        // Connexion : dernière connexion posée
        self::db()->exec("UPDATE \"user\" SET is_verified = true WHERE email = '$email'");
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json'], json_encode(['username' => $email, 'password' => 'Mot-de-passe-1!', 'platform' => 'mobile']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->assertNotNull(self::db()->query("SELECT last_login_at FROM \"user\" WHERE email = '$email'")->fetchColumn());
    }

    public function testMobileUsersAreListedWithTheirActivityAndOthersAreNot(): void
    {
        $mobile = $this->userWithToken('mobile-' . bin2hex(random_bytes(3)) . '@example.invalid', User::SIGNUP_MOBILE);
        $webUser = $this->userWithToken('webclient-' . bin2hex(random_bytes(3)) . '@example.invalid', User::SIGNUP_WEB_INVITE);
        $book = $this->json('POST', '/api/memoires/mobile/books', ['title' => 'Livre de Jeanne', 'type' => 'famille'], $mobile['token']);
        $this->assertSame('mobile', $book['channel']);
        $chapter = $this->json('POST', "/api/memoires/books/{$book['id']}/chapters", ['title' => 'Paroles', 'theme' => 'regards_croises', 'position' => 2, 'answers' => [['index' => 0, 'question' => 'Un souvenir ?', 'answer' => 'Oui']]], $mobile['token']);
        $this->json('POST', "/api/memoires/chapters/{$chapter['id']}/improve-answer", ['question' => 'Un souvenir ?', 'answer' => 'Oui, un très beau.', 'index' => 0], $mobile['token']);
        self::db()->exec("UPDATE mv_book SET payment_status = 'paid', payment_amount = 49.9, payment_currency = 'eur', paid_at = NOW() WHERE id = '{$book['id']}'");
        $this->json('POST', '/api/memoires/books', ['title' => 'Livre web', 'type' => 'individuel'], $webUser['token']);

        [$status, $list] = $this->api('GET', '/admin/mobile-users?search=' . urlencode($mobile['email']));
        $this->assertSame(200, $status, json_encode($list));
        $this->assertSame(1, $list['total']);
        $this->assertSame(['transcriptionsPerDay' => 40, 'improvementsPerDay' => 60], $list['limits']);
        $row = $list['users'][0];
        $this->assertSame([$mobile['id'], $mobile['email'], 'mobile'], [$row['userId'], $row['email'], $row['signupChannel']]);
        $this->assertNotNull($row['createdAt']);
        $this->assertNotNull($row['lastActivityAt']);
        $this->assertSame(['count' => 1, 'mobileCount' => 1, 'chaptersTotal' => 1, 'chaptersWritten' => 0, 'chaptersPaid' => 0], $row['books']);
        $this->assertSame(['count' => 1, 'totalAmount' => 49.9, 'currency' => 'eur'], array_intersect_key($row['payments'], ['count' => 1, 'totalAmount' => 1, 'currency' => 1]));
        $this->assertNotNull($row['payments']['lastPaidAt']);
        $this->assertNull($row['credits']);
        $this->assertSame([0, 1, 0, 1, 0], [$row['ai']['transcriptionsToday'], $row['ai']['improvementsToday'], $row['ai']['transcriptionsTotal'], $row['ai']['improvementsTotal'], $row['ai']['generationsTotal']]);
        $this->assertNotNull($row['ai']['lastCallAt']);
        $this->assertSame(['transcriptions' => 40, 'improvements' => 59], $row['ai']['remaining']);

        [, $all] = $this->api('GET', '/admin/mobile-users?perPage=1&page=1');
        $this->assertSame(1, count($all['users']));
        $this->assertGreaterThanOrEqual(1, $all['total']);
        $ids = [];
        for ($p = 1; $p <= $all['total']; $p++) {
            [, $page] = $this->api('GET', "/admin/mobile-users?perPage=1&page=$p");
            $ids[] = $page['users'][0]['userId'] ?? null;
        }
        $this->assertContains($mobile['id'], $ids);
        $this->assertNotContains($webUser['id'], $ids, 'client web absent');
        $admin = $this->allAdminUsers();
        $adminIds = array_column(array_filter($admin, fn ($u) => in_array('ROLE_ADMIN', $u['roles'], true)), 'id');
        $this->assertSame([], array_intersect($adminIds, $ids), 'administrateurs absents');

        // Détail
        [$status, $detail] = $this->api('GET', '/admin/mobile-users/' . $mobile['id']);
        $this->assertSame(200, $status, json_encode($detail));
        $this->assertSame(['mobile', 'mobile'], [$detail['user']['population'], $detail['user']['signupChannel']]);
        $this->assertSame($book['id'], $detail['books'][0]['id']);
        $this->assertSame(['mobile', 'paid'], [$detail['books'][0]['channel'], $detail['books'][0]['paymentStatus']]);
        $this->assertSame([$chapter['id'], false, null], [$detail['books'][0]['chapters'][0]['id'], $detail['books'][0]['chapters'][0]['hasText'], $detail['books'][0]['chapters'][0]['paid']]);
        $this->assertSame(['kind' => 'book', 'id' => $book['id'], 'title' => 'Livre de Jeanne'], $detail['payments'][0]['object']);
        $this->assertSame([49.9, 'eur', 'paid'], [$detail['payments'][0]['amount'], $detail['payments'][0]['currency'], $detail['payments'][0]['status']]);
        $this->assertNull($detail['credits']);
        $this->assertSame(1, $detail['ai']['improvementsTotal']);
        $this->assertCount(1, $detail['ai']['history']);
        $this->assertSame(1, $detail['ai']['history'][0]['improvements']);
        $this->assertSame(404, $this->api('GET', '/admin/mobile-users/' . $webUser['id'])[0], 'client web : hors population');
        $this->assertSame(404, $this->api('GET', '/admin/mobile-users/999999')[0]);

        // GET /admin/users : champs ajoutés seulement
        $users = $this->allAdminUsers();
        $byId = array_column($users, null, 'id');
        $this->assertSame(['mobile', 'mobile', 1], [$byId[$mobile['id']]['population'], $byId[$mobile['id']]['signupChannel'], $byId[$mobile['id']]['mobileBooksCount']]);
        $this->assertSame(['web_client', 'web_invite', 0], [$byId[$webUser['id']]['population'], $byId[$webUser['id']]['signupChannel'], $byId[$webUser['id']]['mobileBooksCount']]);
        $this->assertNotNull($byId[$webUser['id']]['lastActivityAt'], 'dérivée du livre web');
        $this->assertSame('team', $byId[$adminIds[0]]['population']);
        foreach (['id', 'email', 'fullName', 'roles', 'role', 'booksCount', 'createdAt', 'lastActivityAt'] as $key) {
            $this->assertArrayHasKey($key, $byId[$mobile['id']]);
        }

        // Un client web qui crée un livre dans l'application devient « mobile »
        $this->json('POST', '/api/memoires/mobile/books', ['title' => 'Essai depuis le téléphone', 'type' => 'individuel'], $webUser['token']);
        $users = $this->allAdminUsers();
        $this->assertSame('mobile', array_column($users, null, 'id')[$webUser['id']]['population']);
        $this->assertSame(200, $this->api('GET', '/admin/mobile-users/' . $webUser['id'])[0]);

        $this->assertSame(403, $this->api('GET', '/admin/mobile-users', null, 'client')[0]);
        $this->assertSame(403, $this->api('GET', '/admin/mobile-users/' . $mobile['id'], null, 'client')[0]);
    }

    private function register(string $email, array $extra): int
    {
        // Adresse source propre : /api/register est limité par IP et toute la suite partage l'adresse de test
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/register', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.77.' . random_int(1, 254) . '.' . random_int(1, 254)], json_encode(['email' => $email, 'password' => 'Mot-de-passe-1!', 'firstName' => 'Jeanne', 'lastName' => 'Dupont', 'platform' => 'mobile'] + $extra));

        return $this->client->getResponse()->getStatusCode();
    }

    /** @return array{id: int, email: string, token: string} */
    private function userWithToken(string $email, string $signupChannel): array
    {
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Jeanne')->setLastname('Dupont')->setRoles(['ROLE_USER_INTERNET'])->setPassword('inutilisable')->setIsVerified(true)->setSignupChannel($signupChannel);
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return ['id' => (int) $user->getId(), 'email' => $email, 'token' => $container->get('lexik_jwt_authentication.jwt_manager')->create($user)];
    }

    private function json(string $method, string $path, array $body, string $token): array
    {
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path . '?locale=fr', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_XSRF_TOKEN' => 'x', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token], json_encode($body));
        $response = $this->client->getResponse();
        $this->assertContains($response->getStatusCode(), [200, 201], "$method $path : " . $response->getContent());

        return json_decode((string) $response->getContent(), true);
    }
}
