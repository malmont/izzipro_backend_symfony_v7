<?php

namespace App\Tests\Functional\Admin;

use App\Controller\Admin\SharedMediaCrudController;
use App\Entity\User;
use App\Message\PrepareScrollVideoMessage;
use App\MessageHandler\PrepareScrollVideoHandler;
use App\Tests\Fake\FakeScrollVideoEncoder;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Médiathèque partagée dans l'administration : la liste et la fiche s'ouvrent dès qu'un média existe
 * (régression du 02/10/2026 : erreur 500 sur la liste, compteur de vues affiché par un champ texte).
 */
class SharedMediaEasyAdminTest extends WebTestCase
{
    private const KEY = 'cd34cd34cd34cd34cd34cd34cd34cd34cd34cd34cd34cd34cd34cd34cd34cd34';

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

    public function testVideoIsPreparedForScrollInTheBackgroundAndCanBeRestored(): void
    {
        FakeScrollVideoEncoder::reset();
        [$id, $dir] = $this->privateVideo('film_aabbccdd00112233.mp4');
        $this->loginAs(['ROLE_ADMIN']);

        // bouton de la fiche : la demande part en tâche de fond, rien n'est encodé par le site
        $this->admin('detail', $id);
        $link = $this->client->getCrawler()->filter('a[href*="prepareScrollAction"]');
        $this->assertCount(1, $link, 'bouton « Préparer pour le défilement » sur la fiche d\'une vidéo');
        $this->client->request('GET', $link->attr('href'), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $messages = array_map(fn ($envelope) => $envelope->getMessage(), static::getContainer()->get('messenger.transport.media')->getSent());
        $this->assertCount(1, $messages);
        $this->assertSame([], FakeScrollVideoEncoder::$calls);
        $this->assertSame('pending', $this->row($id)['scroll_status']);

        // passage du worker : fichier préparé servi, original conservé, clé inchangée
        static::getContainer()->get(PrepareScrollVideoHandler::class)($messages[0]);
        $row = $this->row($id);
        $this->assertSame('done', $row['scroll_status']);
        $this->assertSame('film_aabbccdd00112233_scroll.mp4', $row['filename']);
        $this->assertSame('film_aabbccdd00112233.mp4', $row['source_filename']);
        $this->assertSame(self::KEY, $row['access_key']);
        $this->assertSame(strlen(FakeScrollVideoEncoder::OUTPUT), (int) $row['file_size']);
        $this->assertFileExists("$dir/film_aabbccdd00112233.mp4");
        $this->assertStringEqualsFile("$dir/film_aabbccdd00112233_scroll.mp4", FakeScrollVideoEncoder::OUTPUT);

        // retour à l'original
        $this->admin('detail', $id);
        $this->assertStringContainsString('Prête pour le défilement', $this->client->getResponse()->getContent());
        $this->assertCount(0, $this->client->getCrawler()->filter('a[href*="prepareScrollAction"]'));
        $this->client->request('GET', $this->client->getCrawler()->filter('a[href*="restoreScrollAction"]')->attr('href'), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $row = $this->row($id);
        $this->assertNull($row['scroll_status']);
        $this->assertSame('film_aabbccdd00112233.mp4', $row['filename']);
        $this->assertNull($row['source_filename']);
        $this->assertFileDoesNotExist("$dir/film_aabbccdd00112233_scroll.mp4");
        @unlink("$dir/film_aabbccdd00112233.mp4");
    }

    public function testFailedEncodingKeepsTheOriginalVideo(): void
    {
        FakeScrollVideoEncoder::reset();
        FakeScrollVideoEncoder::$fail = true;
        [$id, $dir] = $this->privateVideo('film_ffeeddcc00112233.mp4');
        $this->db()->executeStatement("UPDATE shared_media SET scroll_status = 'pending' WHERE id = $id");

        static::getContainer()->get(PrepareScrollVideoHandler::class)(new PrepareScrollVideoMessage($id, MV_TEST_TENANT_CODE));

        $row = $this->row($id);
        $this->assertSame('failed', $row['scroll_status']);
        $this->assertSame('film_ffeeddcc00112233.mp4', $row['filename'], 'la vidéo d\'origine reste servie');
        $this->assertNull($row['source_filename']);
        $this->assertFileDoesNotExist("$dir/film_ffeeddcc00112233_scroll.mp4");
        @unlink("$dir/film_ffeeddcc00112233.mp4");
        FakeScrollVideoEncoder::reset();
    }

    public function testPrepareLinkWithoutAValidTokenDoesNothing(): void
    {
        [$id] = $this->privateVideo('film_0000111122223333.mp4');
        $this->loginAs(['ROLE_ADMIN']);

        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query([
            'crudAction' => 'prepareScrollAction', 'crudControllerFqcn' => SharedMediaCrudController::class, 'entityId' => $id, '_csrf' => 'faux',
        ]), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);

        $this->assertNull($this->row($id)['scroll_status']);
        $this->assertSame([], static::getContainer()->get('messenger.transport.media')->getSent());
        @unlink(static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/private_media/film_0000111122223333.mp4');
    }

    /** @return array{int, string} identifiant du média et dossier de son fichier */
    private function privateVideo(string $filename): array
    {
        $dir = static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/private_media';
        file_put_contents("$dir/$filename", 'video-d-origine');
        $this->db()->executeStatement(sprintf("INSERT INTO shared_media (titre, filename, original_filename, media_type, mime_type, file_size, visibility, access_key, created_at)
            VALUES ('Vidéo de test', '%s', 'film.mp4', 'video', 'video/mp4', 15, 'private', '%s', NOW())", $filename, self::KEY));

        return [(int) $this->db()->fetchOne('SELECT id FROM shared_media ORDER BY id DESC LIMIT 1'), $dir];
    }

    private function row(int $id): array
    {
        return $this->db()->fetchAssociative('SELECT * FROM shared_media WHERE id = ?', [$id]);
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
