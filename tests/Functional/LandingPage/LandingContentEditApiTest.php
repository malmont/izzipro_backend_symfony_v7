<?php

namespace App\Tests\Functional\LandingPage;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Modification des contenus de section depuis l'éditeur des landing pages (PATCH /api/{ressource}/{id}) et
 * téléversement de médias (POST /api/media).
 */
class LandingContentEditApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private const IMAGE_KEY = 'ab01ab01ab01ab01ab01ab01ab01ab01ab01ab01ab01ab01ab01ab01ab01ab01';
    private const VIDEO_KEY = 'cd02cd02cd02cd02cd02cd02cd02cd02cd02cd02cd02cd02cd02cd02cd02cd02';

    private KernelBrowser $client;
    private array $session = [];
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        foreach (['presentation_translation', 'presentation_group_presentation', 'presentation', 'video_translation', 'video', 'shared_media'] as $table) {
            $this->db()->executeStatement("DELETE FROM $table");
        }
        $this->db()->executeStatement("INSERT INTO shared_media (titre, filename, media_type, mime_type, visibility, access_key, created_at) VALUES
            ('Photo', 'photo.jpg', 'image', 'image/jpeg', 'private', '" . self::IMAGE_KEY . "', NOW()),
            ('Film', 'film.mp4', 'video', 'video/mp4', 'private', '" . self::VIDEO_KEY . "', NOW())");
        $this->session = $this->login(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testFrenchPatchWritesTheBaseAndTheFrenchTranslation(): void
    {
        $id = $this->presentation('Ancien titre', ['fr' => 'Titre FR', 'en' => 'Title EN']);

        $response = $this->patch("/api/presentations/$id?locale=fr", [
            'titre' => 'Nouveau <span>titre</span>', 'texte' => '<p>Texte <strong>gras</strong> et <a href="https://exemple.com">lien</a></p>',
            'texteBouton' => 'Nous écrire', 'lienBouton' => 'mailto:contact@example.invalid', 'image' => self::IMAGE_KEY,
        ]);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent());
        $this->assertSame('Nouveau <span>titre</span>', $body->titre);
        $this->assertSame('https://' . MV_TEST_TENANT_HOST . '/media/secure/' . self::IMAGE_KEY, $body->image, 'image servie par sa clé');
        $row = $this->db()->fetchAssociative('SELECT * FROM presentation WHERE id = ?', [$id]);
        $this->assertSame('Nouveau <span>titre</span>', $row['titre'], 'base mise à jour en français');
        $this->assertSame('/media/secure/' . self::IMAGE_KEY, $row['image']);
        $this->assertSame('Nouveau <span>titre</span>', $this->translation($id, 'fr')['titre']);
        $this->assertSame('Title EN', $this->translation($id, 'en')['titre'], 'anglais intact');

        $this->assertSame('Nouveau <span>titre</span>', json_decode($this->request('GET', "/api/presentations/$id?locale=fr")->getContent())->titre, 'cache du GET invalidé');
    }

    public function testOtherLanguageCreatesItsTranslationAndLeavesTheBaseUntouched(): void
    {
        $id = $this->presentation('Titre de base', ['fr' => 'Titre FR']);

        $response = $this->patch("/api/presentations/$id?locale=en", ['texte' => '<p>English text</p>']);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertSame('<p>English text</p>', json_decode($response->getContent())->texte);
        $en = $this->translation($id, 'en');
        $this->assertSame('<p>English text</p>', $en['texte']);
        $this->assertSame('Titre de base', $en['titre'], 'titre de la nouvelle traduction : le titre de base');
        $this->assertNull($this->db()->fetchOne('SELECT texte FROM presentation WHERE id = ?', [$id]));
        $this->assertNull($this->translation($id, 'fr')['texte']);
    }

    public function testVideoFileAndPosterByKey(): void
    {
        $this->db()->executeStatement("INSERT INTO video (id, titre) VALUES (nextval('video_id_seq'), 'Vidéo')");
        $id = (int) $this->db()->fetchOne('SELECT MAX(id) FROM video');

        $response = $this->patch("/api/videos/$id", ['lienVideo' => self::VIDEO_KEY, 'imageDeFond' => self::IMAGE_KEY, 'description' => 'Une scène']);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent());
        $this->assertSame('https://' . MV_TEST_TENANT_HOST . '/media/secure/' . self::VIDEO_KEY, $body->lienVideo);
        $this->assertSame('https://' . MV_TEST_TENANT_HOST . '/media/secure/' . self::IMAGE_KEY, $body->imageDeFondUrl);
    }

    public function testRefusedFieldsAreListedWithTheirPath(): void
    {
        $id = $this->presentation('Titre', []);
        $before = $this->db()->fetchAssociative('SELECT * FROM presentation WHERE id = ?', [$id]);

        $response = $this->patch("/api/presentations/$id", [
            'titre' => '', 'texte' => '<a href="javascript:alert(1)">piège</a>', 'lienBouton' => 'javascript:alert(1)',
            'image' => self::VIDEO_KEY, 'prix' => '10', 'texteBouton' => 12,
        ]);

        $this->assertSame(422, $response->getStatusCode(), $response->getContent());
        $paths = array_column(json_decode($response->getContent(), true)['errors'], 'path');
        $this->assertEqualsCanonicalizing(['titre', 'texte', 'lienBouton', 'image', 'prix', 'texteBouton'], $paths);
        $this->assertSame($before, $this->db()->fetchAssociative('SELECT * FROM presentation WHERE id = ?', [$id]), 'rien n\'est écrit');

        $this->assertSame(422, $this->patch("/api/presentations/$id", ['image' => str_repeat('ef', 32)])->getStatusCode(), 'clé inconnue');
        $this->assertSame(404, $this->patch('/api/presentations/999999', ['titre' => 'X'])->getStatusCode());
        $this->assertSame(400, $this->patch("/api/presentations/$id?locale=français", ['titre' => 'X'])->getStatusCode());
        $this->assertSame(400, $this->patch("/api/presentations/$id", [])->getStatusCode());
    }

    public function testOnlyAnAdminCanPatch(): void
    {
        $id = $this->presentation('Titre', []);
        $this->session = $this->login(['ROLE_USER_INTERNET']);
        $this->assertSame(403, $this->patch("/api/presentations/$id", ['titre' => 'Pirate'])->getStatusCode());
        $this->session = [];
        $this->assertSame(401, $this->patch("/api/presentations/$id", ['titre' => 'Pirate'])->getStatusCode());
        $this->assertSame('Titre', $this->db()->fetchOne('SELECT titre FROM presentation WHERE id = ?', [$id]));
    }

    public function testUploadedImageIsAPrivateMediaUsableByItsKey(): void
    {
        $response = $this->upload('photo.png', $this->png(), ['title' => 'Portrait']);

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $body->key);
        $this->assertSame('image', $body->type);
        $this->assertSame('Portrait', $body->title);
        $this->assertSame('https://' . MV_TEST_TENANT_HOST . '/media/secure/' . $body->key, $body->url);
        $row = $this->db()->fetchAssociative('SELECT * FROM shared_media WHERE access_key = ?', [$body->key]);
        $this->assertSame('private', $row['visibility']);
        $this->files[] = $file = static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/private_media/' . $row['filename'];
        $this->assertFileExists($file);

        $id = $this->presentation('Titre', []);
        $this->assertSame(200, $this->patch("/api/presentations/$id", ['image' => $body->key])->getStatusCode(), 'la clé s\'utilise aussitôt');
    }

    public function testUploadRefusesOtherFormatsAndAnonymousUsers(): void
    {
        $this->assertSame(415, $this->upload('notes.pdf', "%PDF-1.4\n%fake\n")->getStatusCode(), 'ni document ni PDF');
        $this->assertSame(415, $this->upload('photo.png', '<html><script>alert(1)</script></html>')->getStatusCode(), 'contenu qui ne correspond pas à l\'extension');
        $this->assertSame(400, $this->request('POST', '/api/media')->getStatusCode(), 'fichier absent');
        $this->session = [];
        $this->assertSame(401, $this->upload('photo.png', $this->png())->getStatusCode());
    }

    public function testUploadedVideoCanBePreparedForScroll(): void
    {
        $response = $this->upload('film.mp4', $this->mp4(), ['prepareForScroll' => '1']);

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent());
        $this->assertSame('video', $body->type);
        $this->assertSame('pending', $body->scrollStatus);
        $this->assertCount(1, static::getContainer()->get('messenger.transport.media')->getSent());
        $this->files[] = static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/private_media/' . $this->db()->fetchOne('SELECT filename FROM shared_media WHERE access_key = ?', [$body->key]);
    }

    public function testCompanyPutInEnglishNoLongerOverwritesTheFrenchLegalTexts(): void
    {
        $id = (int) $this->db()->fetchOne('SELECT MIN(id) FROM entreprise');
        $this->db()->executeStatement('DELETE FROM entreprise_translation WHERE entreprise_id = ?', [$id]);
        $this->db()->executeStatement("INSERT INTO entreprise_translation (id, entreprise_id, language, legal_notice) VALUES (nextval('entreprise_translation_id_seq'), ?, 'fr', 'Mentions FR')", [$id]);

        $response = $this->request('PUT', "/api/entreprise/$id?locale=en", json_encode(['LegalNotice' => 'Legal notice EN']));

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $rows = $this->db()->fetchAllKeyValue('SELECT language, legal_notice FROM entreprise_translation WHERE entreprise_id = ? ORDER BY language', [$id]);
        $this->assertSame(['en' => 'Legal notice EN', 'fr' => 'Mentions FR'], $rows);
    }

    private function presentation(string $titre, array $translations): int
    {
        $this->db()->executeStatement("INSERT INTO presentation (id, titre) VALUES (nextval('presentation_id_seq'), ?)", [$titre]);
        $id = (int) $this->db()->fetchOne('SELECT MAX(id) FROM presentation');
        foreach ($translations as $language => $title) {
            $this->db()->executeStatement("INSERT INTO presentation_translation (id, presentation_id, language, titre) VALUES (nextval('presentation_translation_id_seq'), ?, ?, ?)", [$id, $language, $title]);
        }

        return $id;
    }

    private function translation(int $id, string $language): array|false
    {
        return $this->db()->fetchAssociative('SELECT * FROM presentation_translation WHERE presentation_id = ? AND language = ?', [$id, $language]);
    }

    private function patch(string $path, array $body): Response
    {
        return $this->request('PATCH', $path, json_encode($body));
    }

    private function upload(string $name, string $content, array $fields = []): Response
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $content);
        $this->files[] = $path;

        return $this->request('POST', '/api/media', null, $fields, ['file' => new UploadedFile($path, $name, null, null, true)]);
    }

    private function request(string $method, string $path, ?string $body = null, array $parameters = [], array $files = []): Response
    {
        $jar = $this->client->getCookieJar();
        $jar->clear();
        foreach ($this->session as $name => $value) {
            $jar->set(new \Symfony\Component\BrowserKit\Cookie($name, $value, null, '/', MV_TEST_TENANT_HOST, true));
        }
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, $parameters, $files, array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST,
            'CONTENT_TYPE' => $body !== null ? 'application/json' : null,
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $this->session['XSRF-TOKEN_' . MV_TEST_TENANT_CODE] ?? null,
        ]), $body);

        return $this->client->getResponse();
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** En-tête MP4 minimal (ftyp) : reconnu comme video/mp4 */
    private function mp4(): string
    {
        return pack('N', 24) . 'ftypisom' . pack('N', 512) . 'isomiso2' . str_repeat("\0", 64);
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager()->getConnection();
    }

    /** @return array<string, string> cookies de session */
    private function login(array $roles): array
    {
        $email = 'landing-content-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Landing')->setLastname('Contenu')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'web']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $session = [];
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            $session[$cookie->getName()] = $cookie->getValue();
        }

        return $session;
    }
}
