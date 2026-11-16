<?php

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Train de sécurité du 16/11/2026 : LE LIEN D'ACTIVATION POSE UN MOT DE PASSE. La route publique d'activation
 * (`POST /api/memoires/auth/activate`) activait tout compte non vérifié sur présentation de son jeton de vérification, sans
 * échéance, y compris un client inscrit par la boutique ; et la console rendait ce jeton pour tout compte en attente, à
 * l'administrateur comme au responsable des biographes (qui pouvait ainsi s'activer à la place d'un administrateur invité).
 *
 * Désormais : seul un compte né d'une invitation de la console s'active par ce lien, dans les 7 jours, une seule fois ; le lien n'est
 * rendu qu'à l'administrateur du site.
 */
class ActivationLinkTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
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
        $this->db->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'activation-%@example.invalid'");
        parent::tearDown();
    }

    public function testOnlyAnAccountInvitedByTheConsoleActivatesByItsLinkWithinSevenDaysAndOnce(): void
    {
        $admin = $this->bearer('admin', ['ROLE_ADMIN']);
        $manager = $this->bearer('responsable', ['ROLE_USER', 'ROLE_USER_INTERNET', 'ROLE_MEMOIRES_RESPONSABLE']);
        $email = "activation-biographe-{$this->tag}@example.invalid";

        // L'INVITATION par l'administrateur : le lien lui est rendu, et il vaut 7 jours
        [$status, $created] = $this->api('POST', '/api/memoires/admin/users', ['email' => $email, 'firstName' => 'Bio', 'lastName' => 'Graphe'], $admin);
        $this->assertSame(201, $status, json_encode($created));
        $token = (string) $created['user']['token'];
        $this->assertStringEndsWith('/activer-compte?token=' . $token, (string) $created['user']['activationUrl']);
        $id = (int) $created['user']['id'];
        $expires = new \DateTimeImmutable((string) $this->db->fetchOne('SELECT verification_token_expires_at FROM "user" WHERE id = ?', [$id]));
        $days = (new \DateTimeImmutable())->diff($expires)->days;
        $this->assertContains($days, [6, 7], 'le lien vaut 7 jours');
        $this->assertSame(User::SIGNUP_WEB_INVITE, $this->db->fetchOne('SELECT signup_channel FROM "user" WHERE id = ?', [$id]));

        // LA LISTE : le lien n'est rendu qu'à l'ADMINISTRATEUR. Le responsable des biographes voit le compte « en attente », sans son lien
        $seenByAdmin = $this->listed($admin, $email);
        $this->assertSame([true, true], [$seenByAdmin['isPending'], str_ends_with((string) $seenByAdmin['activationUrl'], $token)]);
        $seenByManager = $this->listed($manager, $email);
        $this->assertSame([true, null], [$seenByManager['isPending'], $seenByManager['activationUrl']]);
        $this->assertStringNotContainsString($token, (string) $this->client->getResponse()->getContent(), 'le jeton n\'est nulle part dans la réponse du responsable');
        // … de même à la création : le responsable qui invite un biographe ne reçoit ni le jeton ni le lien (le courriel, lui, part)
        [$status, $byManager] = $this->api('POST', '/api/memoires/admin/users', ['email' => "activation-invite-par-resp-{$this->tag}@example.invalid", 'firstName' => 'Autre', 'lastName' => 'Biographe'], $manager);
        $this->assertSame(201, $status, json_encode($byManager));
        $this->assertSame([null, null, true], [$byManager['user']['token'], $byManager['user']['activationUrl'], $byManager['user']['isPending']]);

        // UN LIEN EXPIRÉ, ou sans échéance, ne vaut rien
        $this->db->executeStatement("UPDATE \"user\" SET verification_token_expires_at = NOW() - INTERVAL '1 minute' WHERE id = ?", [$id]);
        $this->assertSame([404, 'ACTIVATION_LINK_INVALID'], $this->activate($token));
        $this->assertNull($this->listed($admin, $email)['activationUrl'], 'un lien expiré n\'est plus rendu');
        $this->db->executeStatement('UPDATE "user" SET verification_token_expires_at = NULL WHERE id = ?', [$id]);
        $this->assertSame([404, 'ACTIVATION_LINK_INVALID'], $this->activate($token));
        $this->assertFalse((bool) $this->db->fetchOne('SELECT is_verified FROM "user" WHERE id = ?', [$id]));

        // LE LIEN VALIDE active le compte UNE fois : mot de passe choisi, jeton et échéance effacés
        $this->db->executeStatement("UPDATE \"user\" SET verification_token_expires_at = NOW() + INTERVAL '2 days' WHERE id = ?", [$id]);
        $this->assertSame(200, $this->activate($token)[0]);
        $after = $this->db->fetchAssociative('SELECT is_verified, verification_token, verification_token_expires_at FROM "user" WHERE id = ?', [$id]);
        $this->assertSame([true, null, null], [(bool) $after['is_verified'], $after['verification_token'], $after['verification_token_expires_at']]);
        $this->assertSame([404, 'ACTIVATION_LINK_INVALID'], $this->activate($token), 'une seule fois');
        $this->assertNull($this->listed($admin, $email)['activationUrl']);

        // UN COMPTE INSCRIT PAR LA BOUTIQUE n'est JAMAIS activable par cette route : son jeton ne sert qu'à vérifier son adresse.
        // Même avec une échéance à venir, la route le refuse, et son mot de passe ne change pas.
        foreach ([User::SIGNUP_WEB_REGISTER, User::SIGNUP_MOBILE, null] as $i => $channel) {
            $shopper = "activation-client-$i-{$this->tag}@example.invalid";
            $shopToken = 'jeton-client-' . bin2hex(random_bytes(8));
            $this->db->executeStatement("INSERT INTO \"user\" (id, email, username, firstname, lastname, roles, password, is_verified, signup_channel, verification_token, verification_token_expires_at, created_at)"
                . " VALUES (nextval('user_id_seq'), ?, ?, 'Client', 'Boutique', '[\"ROLE_USER_INTERNET\"]', 'mot-de-passe-du-client', false, ?, ?, NOW() + INTERVAL '3 days', NOW())", [$shopper, $shopper, $channel, $shopToken]);
            $this->assertSame([404, 'ACTIVATION_LINK_INVALID'], $this->activate($shopToken), 'canal ' . ($channel ?? 'inconnu'));
            $row = $this->db->fetchAssociative('SELECT password, is_verified, verification_token FROM "user" WHERE email = ?', [$shopper]);
            $this->assertSame(['mot-de-passe-du-client', false, $shopToken], [$row['password'], (bool) $row['is_verified'], $row['verification_token']], 'le compte du client n\'a pas bougé');
            $this->assertSame([true, null], [$this->listed($admin, $shopper)['isPending'], $this->listed($admin, $shopper)['activationUrl']], 'son jeton n\'est rendu à personne');
        }

        // UN ADMINISTRATEUR INVITÉ : le responsable des biographes ne peut ni le créer, ni lire son lien
        $this->assertSame(403, $this->api('POST', '/api/memoires/admin/users', ['email' => "activation-admin-refuse-{$this->tag}@example.invalid", 'firstName' => 'A', 'lastName' => 'B', 'role' => 'ROLE_ADMIN'], $manager)[0]);
        $adminEmail = "activation-nouvel-admin-{$this->tag}@example.invalid";
        [$status, $invitedAdmin] = $this->api('POST', '/api/memoires/admin/users', ['email' => $adminEmail, 'firstName' => 'Nouvel', 'lastName' => 'Administrateur', 'role' => 'ROLE_ADMIN'], $admin);
        $this->assertSame(201, $status, json_encode($invitedAdmin));
        $this->assertNull($this->listed($manager, $adminEmail)['activationUrl']);
        $this->assertStringNotContainsString((string) $invitedAdmin['user']['token'], (string) $this->client->getResponse()->getContent());
    }

    /** @return array<string, mixed> la ligne d'un compte dans la liste de la console */
    private function listed(string $bearer, string $email): array
    {
        [$status, $body] = $this->api('GET', '/api/memoires/admin/users?limit=200&search=' . urlencode($email), null, $bearer);
        $this->assertSame(200, $status, json_encode($body));
        $rows = $body['users'] ?? $body['data'] ?? $body['items'] ?? $body;
        foreach ($rows as $row) {
            if (is_array($row) && ($row['email'] ?? null) === $email) {
                return $row;
            }
        }
        $this->fail("compte $email absent de la liste");
    }

    /** @return array{0: int, 1: ?string} */
    private function activate(string $token): array
    {
        [$status, $body] = $this->api('POST', '/api/memoires/auth/activate', ['token' => $token, 'password' => 'Mot-de-passe-choisi-11'], null);

        return [$status, $body['code'] ?? null];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function api(string $method, string $path, ?array $body, ?string $bearer): array
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '10.81.' . random_int(1, 254) . '.' . random_int(1, 254)] + ($bearer !== null ? ['HTTP_AUTHORIZATION' => 'Bearer ' . $bearer] : []), $body !== null ? json_encode($body) : null);

        return [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true) ?? []];
    }

    private function bearer(string $name, array $roles): string
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $provider->getEntityManager();
        $email = "activation-$name-{$this->tag}@example.invalid";
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Activation')->setLastname($name)->setRoles($roles)->setPassword('x')->setIsVerified(true);
        $em->persist($user);
        $em->flush();

        return static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }
}
