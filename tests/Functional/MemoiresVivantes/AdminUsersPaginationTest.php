<?php

namespace App\Tests\Functional\MemoiresVivantes;

/**
 * Liste des utilisateurs de la console Mémoires Vivantes (S8, 26/10/2026) : ?page= et ?limit= (défaut et plafond 200), total dans
 * X-Total-Count (exposé au navigateur), corps toujours un tableau ; sans paramètre, la réponse d'avant tant que le site a au plus 200 comptes.
 */
class AdminUsersPaginationTest extends BookTypeApiTestCase
{
    public function testTheUserListIsPaginatedWithItsTotalInAHeader(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->api('POST', '/admin/users', ['email' => 'page-' . bin2hex(random_bytes(3)) . '@example.invalid', 'firstName' => 'Page', 'lastName' => 'Test ' . $i, 'role' => 'ROLE_USER'], 'admin');
        }
        [$status, $all] = $this->api('GET', '/admin/users', null, 'admin');
        $this->assertSame(200, $status);
        $total = (int) $this->client->getResponse()->headers->get('X-Total-Count');
        $this->assertSame(min($total, 200), count($all), 'sans paramètre : les 200 premiers comptes (tous, tant que le site en a au plus 200, comme avant)');
        $this->assertCount($total, $this->allAdminUsers(), 'toutes les pages réunies : tous les comptes');
        $this->assertStringContainsString('X-Total-Count', (string) $this->client->getResponse()->headers->get('Access-Control-Expose-Headers'));
        $this->assertTrue(array_is_list($all), 'corps toujours un tableau');

        [, $first] = $this->api('GET', '/admin/users?page=1&limit=2', null, 'admin');
        [, $second] = $this->api('GET', '/admin/users?page=2&limit=2', null, 'admin');
        $this->assertSame([2, $total], [count($first), (int) $this->client->getResponse()->headers->get('X-Total-Count')]);
        $this->assertSame(array_column(array_slice($all, 0, 4), 'id'), array_merge(array_column($first, 'id'), array_column($second, 'id')), 'pages successives dans l\'ordre des identifiants');
        [, $capped] = $this->api('GET', '/admin/users?limit=5000', null, 'admin');
        $this->assertLessThanOrEqual(200, count($capped), 'plafond de 200');
        [, $beyond] = $this->api('GET', '/admin/users?page=999&limit=200', null, 'admin');
        $this->assertSame([], $beyond, 'page au-delà de la fin : tableau vide');
        [$refused] = $this->api('GET', '/admin/users', null, 'client');
        $this->assertContains($refused, [401, 403], 'administrateur seulement, comme avant');
    }
}
