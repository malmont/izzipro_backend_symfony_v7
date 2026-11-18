<?php

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot PRESETS (18/11/2026, faille trouvée en classant les écritures de G-1) : les préréglages d'administration (`admin_presets`) sont dans
 * la BASE MAÎTRE, communs à tous les sites. Les créer ou les effacer est réservé au super administrateur ; l'administrateur d'un site
 * reçoit 403 et la table ne change pas. La lecture reste ce qu'elle était.
 */
class AdminPresetsTest extends WebTestCase
{
    public function testOnlyThePlatformWritesTheSharedPresets(): void
    {
        $client = static::createClient();
        $master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $tag = bin2hex(random_bytes(3));
        $token = function (array $roles, string $name) use ($tag): string {
            $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
            $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
            $em = $provider->getEntityManager();
            $email = "presets-$name-$tag@example.invalid";
            $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Essai')->setLastname('Essai')->setRoles($roles)->setPassword('inutilisable')->setIsVerified(true);
            $em->persist($user);
            $em->flush();

            return static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        };
        $call = function (string $method, string $path, string $jwt, ?array $body = null) use ($client): int {
            $client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt, 'REMOTE_ADDR' => '10.93.' . random_int(1, 254) . '.' . random_int(1, 254)], $body !== null ? (string) json_encode($body) : null);

            return $client->getResponse()->getStatusCode();
        };
        $count = fn (): int => (int) $master->query('SELECT COUNT(*) FROM admin_presets')->fetchColumn();
        try {
            $before = $count();
            $admin = $token(['ROLE_ADMIN'], 'admin');
            $this->assertSame(403, $call('POST', '/api/admin-settings/presets', $admin, ['title' => 'Préréglage ' . $tag, 'settings' => ['a' => 1]]));
            $this->assertSame($before, $count(), 'rien n\'est écrit dans la base maître');
            $id = (int) $master->query("INSERT INTO admin_presets (title, settings, created_at) VALUES ('presets-$tag', '{}', NOW()) RETURNING id")->fetchColumn();
            $this->assertSame(403, $call('DELETE', "/api/admin-settings/presets/$id", $admin));
            $this->assertSame($before + 1, $count(), 'rien n\'est effacé');
            $super = $token(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], 'super');
            $this->assertLessThan(300, $call('DELETE', "/api/admin-settings/presets/$id", $super), 'le super administrateur garde le geste');
            $this->assertSame($before, $count());
        } finally {
            $master->exec("DELETE FROM admin_presets WHERE title LIKE 'presets-%' OR title LIKE 'Préréglage %'");
            $static = static::getContainer()->get(\App\Services\Platform\TenantDatabaseConnector::class)->pdo(MV_TEST_TENANT_DB);
            $static->exec("DELETE FROM \"user\" WHERE email LIKE 'presets-%@example.invalid'");
        }
    }
}
