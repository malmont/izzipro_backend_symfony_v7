<?php

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Security\TwoFactorPolicy;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Politique des rôles du 03/11/2026 : le code OTP est toujours imposé à un super administrateur ; l'écran « Compte super administrateur »
 * (réservé au propriétaire) crée ou met à jour le compte sur toutes les bases d'un geste ; le vidage du cache Redis partagé et son
 * entrée de menu sont réservés au propriétaire ; les compteurs techniques survivent au vidage.
 */
class SuperAdminAccountTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->setServerParameter('HTTP_X_TENANT_HOST', MV_TEST_TENANT_HOST);
        $_ENV['ADMIN_OTP_REQUIRED'] = $_SERVER['ADMIN_OTP_REQUIRED'] = '0';
    }

    public function testASuperAdminAlwaysNeedsTheEmailCodeEvenWhenNothingAsksForIt(): void
    {
        $policy = static::getContainer()->get(TwoFactorPolicy::class);
        $this->assertFalse($policy->adminOtpRequired(), 'réglage global à 0 pendant le test');
        $super = (new User())->setRoles(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'])->setOtpEnabled(false);
        $admin = (new User())->setRoles(['ROLE_ADMIN'])->setOtpEnabled(false);
        $this->assertTrue($policy->required($super), 'super administrateur : code imposé');
        $this->assertFalse($policy->required($admin), 'administrateur de site : inchangé (au choix du compte)');

        // De bout en bout : la connexion d'un super administrateur sans OTP coché demande le code
        $email = $this->user(['ROLE_USER_INTERNET', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->pdo(MV_TEST_TENANT_DB)->exec("UPDATE \"user\" SET otp_enabled = false WHERE email = '$email'");
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(['username' => $email, 'password' => self::PASSWORD]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($body['otp_required'] ?? false, 'code demandé : ' . json_encode($body));
        $this->assertArrayNotHasKey('token', $body, 'aucun jeton avant le code');
    }

    public function testOnlyThePlatformOwnerSeesTheScreenAndFlushesRedis(): void
    {
        $this->loginAs(['ROLE_ADMIN']);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/super-admin');
        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'administrateur de site : écran refusé');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/super-admin/appliquer', ['email' => 'x@example.invalid', 'password' => 'xxxxxxxxxxxxxx', 'confirmation' => 'xxxxxxxxxxxxxx']);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/redis/flush', ['_token' => 'x']);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'vidage Redis refusé à un administrateur de site');
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('Compte super administrateur', $html, 'entrée de menu absente');
        $this->assertStringNotContainsString('admin_redis_flush', $html, 'ni lien ni bouton de vidage Redis');
        $this->assertCount(0, $crawler->filter('form[action*="/admin/redis/flush"]'));

        $this->loginAs(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin');
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Compte super administrateur', $html, 'entrée de menu pour le propriétaire');
        $this->assertGreaterThan(0, $crawler->filter('form[action*="/admin/redis/flush"]')->count(), 'bouton de vidage pour le propriétaire');
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?routeName=admin_super_admin');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), 'écran dans l\'habillage EasyAdmin');
    }

    public function testTheOwnerCreatesThenUpdatesTheAccountOnEveryDatabase(): void
    {
        $this->loginAs(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/super-admin');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $bases = $crawler->filter('table.super-admin-etat tbody tr')->each(fn ($tr) => $tr->attr('data-dbname'));
        $this->assertSame([MV_TEST_TENANT_DB, MV_TEST_TENANT2_DB], $bases, 'une ligne par base de la table tenants');
        $token = $crawler->filter('form.super-admin-form input[name="_token"]')->attr('value');
        $email = 'proprietaire-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $password = 'Un-mot-de-passe-solide-2026!';

        // Saisies refusées : trop court, confirmation différente, courriel invalide ; rien n'est écrit
        foreach ([['email' => $email, 'password' => 'court', 'confirmation' => 'court'], ['email' => $email, 'password' => $password, 'confirmation' => $password . 'x'], ['email' => 'pas-un-courriel', 'password' => $password, 'confirmation' => $password]] as $bad) {
            $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/super-admin/appliquer', $bad + ['_token' => $token]);
            $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        }
        $this->assertSame(0, $this->accountCount($email, MV_TEST_TENANT_DB) + $this->accountCount($email, MV_TEST_TENANT2_DB), 'aucun compte créé par une saisie refusée');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/super-admin/appliquer', ['_token' => 'faux', 'email' => $email, 'password' => $password, 'confirmation' => $password]);
        $this->assertSame(0, $this->accountCount($email, MV_TEST_TENANT_DB), 'jeton invalide : rien');

        // Création sur toutes les bases
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/super-admin/appliquer', ['_token' => $token, 'email' => strtoupper($email), 'password' => $password, 'confirmation' => $password, 'firstname' => 'Michel', 'lastname' => 'Propriétaire']);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $crawler = $this->client->followRedirect();
        $this->assertStringContainsString('Compte appliqué sur 2 base(s)', (string) $this->client->getResponse()->getContent());
        $this->assertSame(['créé', 'créé'], $crawler->filter('table.super-admin-resultats tbody .badge')->each(fn ($b) => trim($b->text())));
        foreach ([MV_TEST_TENANT_DB, MV_TEST_TENANT2_DB] as $db) {
            $row = $this->pdo($db)->query("SELECT roles, otp_enabled, is_verified, password_set, firstname, username FROM \"user\" WHERE email = '$email'")->fetch(\PDO::FETCH_ASSOC);
            $this->assertNotFalse($row, "compte présent dans $db");
            $this->assertEqualsCanonicalizing(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], json_decode($row['roles'], true));
            $this->assertTrue((bool) $row['otp_enabled']);
            $this->assertTrue((bool) $row['is_verified']);
            $this->assertTrue((bool) $row['password_set']);
            $this->assertSame('Michel', $row['firstname']);
            $this->assertSame($email, $row['username'], 'courriel normalisé en minuscules');
        }
        $this->assertStringContainsString($email, (string) $this->client->getResponse()->getContent(), 'le compte apparaît dans l\'état par base');

        // Le mot de passe fonctionne sur le second site aussi (plateforme « admin » : le compte n'a pas ROLE_USER_INTERNET, comme les comptes existants), et le code OTP est demandé
        $this->client->request('POST', 'https://' . MV_TEST_TENANT2_HOST . '/api/login', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT2_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(['username' => $email, 'password' => $password, 'platform' => 'admin']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->assertTrue(json_decode((string) $this->client->getResponse()->getContent(), true)['otp_required'] ?? false);

        // Mise à jour : nouveau mot de passe, rôles conservés et complétés, aucun doublon ; l'ancien mot de passe ne passe plus
        $this->pdo(MV_TEST_TENANT_DB)->exec("UPDATE \"user\" SET roles = '[\"ROLE_USER_INTERNET\"]', otp_enabled = false WHERE email = '$email'");
        $this->loginAs(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/super-admin');
        $token = $crawler->filter('form.super-admin-form input[name="_token"]')->attr('value');
        $newPassword = 'Encore-plus-solide-2027!!';
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/super-admin/appliquer', ['_token' => $token, 'email' => $email, 'password' => $newPassword, 'confirmation' => $newPassword]);
        $crawler = $this->client->followRedirect();
        $this->assertSame(['mis à jour', 'mis à jour'], $crawler->filter('table.super-admin-resultats tbody .badge')->each(fn ($b) => trim($b->text())));
        $this->assertSame(1, $this->accountCount($email, MV_TEST_TENANT_DB));
        $row = $this->pdo(MV_TEST_TENANT_DB)->query("SELECT roles, otp_enabled FROM \"user\" WHERE email = '$email'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertEqualsCanonicalizing(['ROLE_USER_INTERNET', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], json_decode($row['roles'], true), 'rôles existants conservés et complétés');
        $this->assertTrue((bool) $row['otp_enabled'], 'OTP remis');
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(['username' => $email, 'password' => $password, 'platform' => 'admin']));
        $this->assertSame(401, $this->client->getResponse()->getStatusCode(), 'ancien mot de passe refusé');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(['username' => $email, 'password' => $newPassword, 'platform' => 'admin']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), 'nouveau mot de passe accepté (code demandé ensuite)');
    }

    private function accountCount(string $email, string $db): int
    {
        return (int) $this->pdo($db)->query("SELECT COUNT(*) FROM \"user\" WHERE email = '$email'")->fetchColumn();
    }

    private function pdo(string $db): \PDO
    {
        $p = static::getContainer()->get(TenantConnectionManager::class)->getTenantParams();

        return new \PDO(sprintf('pgsql:host=%s;port=%d;dbname=%s', $p['host'], (int) ($p['port'] ?? 5432), $db), $p['user'], $p['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    private function user(array $roles, string $db, string $code): string
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant($db, $code);
        $email = 'proprietaire-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Test')->setLastname('Super')->setRoles($roles)->setIsVerified(true);
        $user->setPassword(static::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $email;
    }

    private function loginAs(array $roles): void
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $email = 'ecran-sa-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Ecran')->setLastname('Test')->setRoles($roles)->setIsVerified(true)->setPassword('x');
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user, 'main');
        \App\Tests\Functional\Support\OtpTestHelper::validateIfRequired($this->client, $user); // super admin : code OTP toujours exigé (03/11/2026)
    }
}
