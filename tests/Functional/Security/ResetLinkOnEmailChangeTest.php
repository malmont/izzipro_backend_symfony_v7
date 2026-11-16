<?php

namespace App\Tests\Functional\Security;

use App\Controller\Admin\UserCrudController;
use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Support\OtpTestHelper;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot RESET-1 (16/11/2026) : quand l'adresse d'un compte change, son lien de réinitialisation en attente est effacé. Ce lien (mot de
 * passe oublié, invitation d'un administrateur) a été envoyé à l'ANCIENNE adresse.
 *
 * Avant ce lot, un administrateur pouvait mettre un instant sa propre boîte sur le compte d'un autre, demander un lien, remettre
 * l'adresse d'origine, puis choisir le mot de passe du compte par ce lien. Et celui qui avait reçu un lien gardait la main sur un
 * compte dont l'adresse était passée à quelqu'un d'autre.
 */
class ResetLinkOnEmailChangeTest extends WebTestCase
{
    private const PASSWORD = 'Un-mot-de-passe-solide-42';

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
        $this->db->executeStatement("DELETE FROM refresh_tokens WHERE username LIKE 'lien-%@example.invalid'");
        $this->db->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'lien-%@example.invalid'");
        parent::tearDown();
    }

    public function testChangingTheAddressOfAnAccountClearsItsPendingResetLinkWhateverThePath(): void
    {
        // L'ENTITÉ : toute voie qui change l'adresse passe par elle
        $user = (new User())->setEmail('lien-a@example.invalid')->setResetToken('jeton')->setResetTokenExpiresAt(new \DateTime('+1 hour'));
        $this->assertSame('jeton', $user->getResetToken(), 'la première adresse d\'un compte neuf n\'efface rien');
        $user->setEmail('lien-a@example.invalid');
        $user->setEmail(' Lien-A@Example.invalid ');
        $this->assertSame(['jeton', true], [$user->getResetToken(), $user->getResetTokenExpiresAt() !== null], 'la même adresse, à la casse et aux espaces près, n\'est pas un changement');
        $user->setEmail('lien-b@example.invalid');
        $this->assertSame([null, null], [$user->getResetToken(), $user->getResetTokenExpiresAt()]);

        // 1. PAR LE PROFIL du compte lui-même
        $own = $this->account('profil', ['ROLE_USER_INTERNET']);
        $token = $this->pendingLink($own);
        $bearer = $this->bearer($own);
        $this->api('PUT', '/api/profile', ['email' => "lien-profil-neuf-{$this->tag}@example.invalid", 'currentPassword' => self::PASSWORD], $bearer);
        $this->assertContains($this->client->getResponse()->getStatusCode(), [200, 201], (string) $this->client->getResponse()->getContent());
        $this->assertSame([null, null], $this->link($own));
        $this->assertNotSame(200, $this->confirm($token), 'le lien envoyé à l\'ancienne adresse ne vaut plus');

        // 2. PAR LA CONSOLE de l'équipe (un administrateur change l'adresse d'un autre compte)
        $admin = $this->bearer($this->account('admin', ['ROLE_ADMIN']));
        $other = $this->account('console', ['ROLE_USER_INTERNET']);
        $token = $this->pendingLink($other);
        $this->api('PUT', "/api/memoires/admin/users/$other?locale=fr", ['email' => "lien-console-neuf-{$this->tag}@example.invalid"], $admin);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->assertSame([null, null], $this->link($other));
        $this->assertNotSame(200, $this->confirm($token));

        // 3. PAR EASYADMIN, et LE SCÉNARIO DE L'ALLER-RETOUR : l'administrateur met sa boîte sur le compte d'un autre, un lien est
        // demandé, il remet l'adresse d'origine : le lien obtenu entre-temps est effacé, il ne choisit pas le mot de passe du compte
        $victim = $this->account('victime', ['ROLE_ADMIN']);
        $original = (string) $this->db->fetchOne('SELECT email FROM "user" WHERE id = ?', [$victim]);
        $hash = (string) $this->db->fetchOne('SELECT password FROM "user" WHERE id = ?', [$victim]);
        $this->loginEasyAdmin(['ROLE_ADMIN']);
        $this->editEmail($victim, "lien-boite-de-l-autre-{$this->tag}@example.invalid");
        $token = $this->pendingLink($victim); // le lien « mot de passe oublié » part vers la boîte de l'autre
        $this->editEmail($victim, $original);
        $this->assertSame([$original, null, null], [(string) $this->db->fetchOne('SELECT email FROM "user" WHERE id = ?', [$victim]), ...$this->link($victim)]);
        $this->client->getCookieJar()->clear();
        $this->assertNotSame(200, $this->confirm($token));
        $this->assertSame($hash, (string) $this->db->fetchOne('SELECT password FROM "user" WHERE id = ?', [$victim]), 'le mot de passe du compte n\'a pas changé');

        // SANS changement d'adresse, rien ne change : un lien en attente reste valable (modifier son prénom, ou renvoyer la même adresse)
        $same = $this->account('inchange', ['ROLE_USER_INTERNET']);
        $token = $this->pendingLink($same);
        $bearer = $this->bearer($same);
        $this->api('PUT', '/api/profile', ['firstname' => 'Autre', 'email' => strtoupper((string) $this->db->fetchOne('SELECT email FROM "user" WHERE id = ?', [$same])), 'currentPassword' => self::PASSWORD], $bearer);
        $this->assertSame($token, $this->link($same)[0]);
        $this->assertSame(200, $this->confirm($token), (string) $this->client->getResponse()->getContent());
    }

    /** @return array{0: ?string, 1: ?string} le lien en attente d'un compte : jeton et échéance */
    private function link(int $userId): array
    {
        $row = $this->db->fetchNumeric('SELECT reset_token, reset_token_expires_at FROM "user" WHERE id = ?', [$userId]);

        return [$row[0], $row[1]];
    }

    /** Pose un lien en attente, comme le fait « mot de passe oublié » */
    private function pendingLink(int $userId): string
    {
        $token = 'lien-' . bin2hex(random_bytes(16));
        $this->db->executeStatement("UPDATE \"user\" SET reset_token = ?, reset_token_expires_at = NOW() + INTERVAL '1 hour' WHERE id = ?", [$token, $userId]);

        return $token;
    }

    private function confirm(string $token): int
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/password-reset/confirm', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '10.83.' . random_int(1, 254) . '.' . random_int(1, 254)], json_encode(['token' => $token, 'password' => 'Nouveau-mot-de-passe-77']));

        return $this->client->getResponse()->getStatusCode();
    }

    private function api(string $method, string $path, array $body, string $bearer): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $bearer], json_encode($body));
    }

    private function editEmail(int $userId, string $email): void
    {
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST];
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => 'edit', 'crudControllerFqcn' => UserCrudController::class, 'entityId' => $userId]), [], [], $server);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->submit($crawler->filter('form[name="User"]')->form(), ['User[email]' => $email]);
        $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 303], 'enregistré');
    }

    private function account(string $name, array $roles): int
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $provider->getEntityManager();
        $email = "lien-$name-{$this->tag}@example.invalid";
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Lien')->setLastname($name)->setRoles($roles)->setIsVerified(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $em->persist($user);
        $em->flush();

        return (int) $user->getId();
    }

    private function bearer(int $userId): string
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($provider->getEntityManager()->find(User::class, $userId));
    }

    private function loginEasyAdmin(array $roles): void
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = $provider->getEntityManager()->find(User::class, $this->account('ecran', $roles));
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);
    }
}
