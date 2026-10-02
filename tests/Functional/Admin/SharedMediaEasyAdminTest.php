<?php

namespace App\Tests\Functional\Admin;

use App\Controller\Admin\SharedMediaCrudController;
use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Médiathèque partagée dans l'administration : la liste et la fiche s'ouvrent dès qu'un média existe
 * (régression du 02/10/2026 : erreur 500 sur la liste, compteur de vues affiché par un champ texte).
 */
class SharedMediaEasyAdminTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->db()->executeStatement('DELETE FROM shared_media');
    }

    public function testListAndDetailOpenWithAPrivateVideo(): void
    {
        $key = str_repeat('ab12', 16);
        $this->db()->executeStatement("INSERT INTO shared_media (titre, filename, original_filename, media_type, mime_type, file_size, visibility, access_key, download_count, created_at)
            VALUES ('Vidéo de démonstration', 'film_0011223344556677.mp4', 'film.mp4', 'video', 'video/mp4', 4014326, 'private', '$key', 3, NOW())");
        $id = (int) $this->db()->fetchOne('SELECT id FROM shared_media');
        $this->loginAs(['ROLE_ADMIN']);

        $this->admin('index');
        $html = $this->client->getResponse()->getContent();
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), substr(strip_tags($html), 0, 600));
        $this->assertStringContainsString('Vidéo de démonstration', $html);
        $this->assertStringContainsString('film.mp4', $html, 'colonne « Fichier & Taille »');
        $this->assertStringContainsString('/media/secure/' . $key, $html, 'lien de partage avec la clé');
        $this->assertStringContainsString('fa-eye', $html, 'compteur de vues');
        $this->assertStringContainsString('fa-video', $html, 'colonne « Type »');
        $this->assertStringNotContainsString('Inaccessible', $html, 'aucune colonne calculée sans valeur');
        $crawler = $this->client->getCrawler();
        $this->assertCount(0, $crawler->filter('td.actions-as-dropdown'), 'actions en ligne : le menu déroulant était coupé par le cadre de la liste');
        $this->assertCount(1, $crawler->filter('td.actions a.action-edit'), 'bouton Modifier visible sur la ligne');
        $this->assertCount(1, $crawler->filter('td.actions a.action-detail'));

        $this->admin('detail', $id);
        $html = $this->client->getResponse()->getContent();
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), substr(strip_tags($html), 0, 600));
        $this->assertStringContainsString($key, $html, 'clé d\'accès sur la fiche');
        $this->assertStringContainsString('Nombre de consultations', $html);
        $this->assertStringContainsString('/media/secure/' . $key, $html, 'lien de partage complet');
        $this->assertStringContainsString('&lt;video src=', $html, 'extrait de code pour une vidéo');
        $this->assertStringNotContainsString('Inaccessible', $html);
    }

    private function admin(string $action, ?int $id = null): void
    {
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(array_filter([
            'crudAction' => $action,
            'crudControllerFqcn' => SharedMediaCrudController::class,
            'entityId' => $id,
        ])), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
    }

    private function loginAs(array $roles): void
    {
        $email = 'ea-media-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Admin')->setLastname('Média')->setRoles($roles)->setIsVerified(true)->setPassword('x');
        $em = $this->em();
        $em->persist($user);
        $em->flush();
        $this->client->loginUser($user, 'main');
    }

    private function em()
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        return $this->em()->getConnection();
    }
}
