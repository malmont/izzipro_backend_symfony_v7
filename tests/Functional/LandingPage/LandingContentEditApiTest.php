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
        foreach (['presentation_translation', 'presentation_group_presentation', 'presentation', 'presentation_group_translation', 'presentation_group', 'video_translation', 'video', 'shared_media', 'content_audit_log'] as $table) {
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

    public function testMediaLibraryIsListedFilteredAndPaged(): void
    {
        $this->db()->executeStatement("INSERT INTO shared_media (titre, filename, media_type, mime_type, visibility, created_at) VALUES
            ('Brochure', 'brochure.pdf', 'document', 'application/pdf', 'public', NOW()),
            ('Logo public', 'logo_public_0123456789.png', 'image', 'image/png', 'public', NOW() + INTERVAL '1 minute')");
        $this->db()->executeStatement("INSERT INTO shared_media (titre, filename, media_type, mime_type, visibility, access_key, expires_at, created_at) VALUES
            ('Expiré', 'vieux.jpg', 'image', 'image/jpeg', 'private', '" . str_repeat('9', 64) . "', NOW() - INTERVAL '1 day', NOW())");

        $all = json_decode($this->request('GET', '/api/media')->getContent(), true);
        $this->assertSame(['items', 'total', 'page', 'limit'], array_keys($all));
        $this->assertSame([3, 1, 40], [$all['total'], $all['page'], $all['limit']], 'images et vidéos, ni document ni lien expiré');
        $this->assertSame('Logo public', $all['items'][0]['title'], 'du plus récent au plus ancien');
        $this->assertNull($all['items'][0]['key'], 'média public : pas de clé, url directe');
        $this->assertSame(['id', 'key', 'url', 'type', 'mimeType', 'size', 'title', 'createdAt', 'width', 'height', 'duration', 'scrollStatus', 'visibility'], array_keys($all['items'][0]));
        $photo = array_values(array_filter($all['items'], fn ($i) => $i['title'] === 'Photo'))[0];
        $this->assertSame(self::IMAGE_KEY, $photo['key']);
        $this->assertSame('https://' . MV_TEST_TENANT_HOST . '/media/secure/' . self::IMAGE_KEY, $photo['url']);

        $videos = json_decode($this->request('GET', '/api/media?type=video')->getContent(), true);
        $this->assertSame(['Film'], array_column($videos['items'], 'title'));
        $this->assertSame(['Photo'], array_column(json_decode($this->request('GET', '/api/media?q=PHO')->getContent(), true)['items'], 'title'), 'titre, sans casse');
        $page2 = json_decode($this->request('GET', '/api/media?limit=2&page=2')->getContent(), true);
        $this->assertSame([3, 2, 2, 1], [$page2['total'], $page2['page'], $page2['limit'], count($page2['items'])]);

        foreach (['type=document', 'page=0', 'limit=101', 'limit=abc'] as $query) {
            $this->assertSame(400, $this->request('GET', "/api/media?$query")->getStatusCode(), $query);
        }
        $this->session = $this->login(['ROLE_USER_INTERNET']);
        $this->assertSame(403, $this->request('GET', '/api/media')->getStatusCode());
    }

    public function testMediaIsRenamedAndOnlyDeletedWhenNoLongerUsed(): void
    {
        $photo = (int) $this->db()->fetchOne('SELECT id FROM shared_media WHERE access_key = ?', [self::IMAGE_KEY]);
        $renamed = $this->request('PATCH', "/api/media/$photo", json_encode(['title' => 'Portrait de l\'équipe']));
        $this->assertSame(200, $renamed->getStatusCode(), $renamed->getContent());
        $this->assertSame('Portrait de l\'équipe', json_decode($renamed->getContent())->title);
        $this->assertSame(422, $this->request('PATCH', "/api/media/$photo", json_encode(['title' => '<b>x</b>']))->getStatusCode());
        $this->assertSame(400, $this->request('PATCH', "/api/media/$photo", json_encode(['title' => 'x', 'key' => 'y']))->getStatusCode());

        // utilisé par une présentation et par les réglages publiés : 409 avec les endroits
        $id = $this->presentation('Équipe', []);
        $this->db()->executeStatement('UPDATE presentation SET image = ? WHERE id = ?', ['/media/secure/' . self::IMAGE_KEY, $id]);
        $settings = $this->db()->fetchOne('SELECT configuration::text FROM landing_page_setting ORDER BY id LIMIT 1');
        $this->db()->executeStatement("UPDATE landing_page_setting SET configuration = ?::json WHERE id = (SELECT MIN(id) FROM landing_page_setting)",
            [json_encode(['tabs' => [['name' => 'Accueil', 'sections' => [['reglableConfig' => ['blocks' => [['id' => 'b', 'type' => 'image', 'mediaKey' => self::IMAGE_KEY]]]]]]]])]);
        try {
            $response = $this->request('DELETE', "/api/media/$photo");
            $this->assertSame(409, $response->getStatusCode(), $response->getContent());
            $usages = json_decode($response->getContent(), true)['usages'];
            $this->assertContains("Présentation n° $id « Équipe » : image", $usages);
            $this->assertContains('Réglages publiés : tabs[0].sections[0].reglableConfig.blocks[0].mediaKey (onglet « Accueil »)', $usages);
            $this->assertNotFalse($this->db()->fetchOne('SELECT id FROM shared_media WHERE id = ?', [$photo]));
        } finally {
            $this->db()->executeStatement('UPDATE landing_page_setting SET configuration = ?::json WHERE id = (SELECT MIN(id) FROM landing_page_setting)', [$settings ?: '{}']);
        }

        // plus utilisé : supprimé, fichier compris
        $file = static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/private_media/film.mp4';
        file_put_contents($file, 'x');
        $this->files[] = $file;
        $film = (int) $this->db()->fetchOne('SELECT id FROM shared_media WHERE access_key = ?', [self::VIDEO_KEY]);
        $this->assertSame(204, $this->request('DELETE', "/api/media/$film")->getStatusCode());
        $this->assertFalse($this->db()->fetchOne('SELECT id FROM shared_media WHERE id = ?', [$film]));
        $this->assertFileDoesNotExist($file);
        $this->assertSame(404, $this->request('DELETE', "/api/media/$film")->getStatusCode());
    }

    public function testGroupPresentationsAreAddedOrderedAndRemoved(): void
    {
        $this->db()->executeStatement("INSERT INTO presentation_group (id, titre) VALUES (nextval('presentation_group_id_seq'), 'Services'), (nextval('presentation_group_id_seq'), 'Autre groupe')");
        [$group, $other] = array_map('intval', $this->db()->fetchFirstColumn('SELECT id FROM presentation_group ORDER BY id'));
        $a = $this->presentation('A', []);
        $b = $this->presentation('B', []);
        foreach ([[$group, $a], [$group, $b], [$other, $b]] as [$g, $p]) {
            $this->db()->executeStatement('INSERT INTO presentation_group_presentation (presentation_group_id, presentation_id) VALUES (?, ?)', [$g, $p]);
        }

        // ajout après A, avec sa traduction
        $response = $this->request('POST', "/api/presentation-groups/$group/presentations?locale=fr", json_encode(['titre' => 'Nouvelle', 'texte' => '<p>Texte</p>', 'after' => $a]));
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $titles = fn ($r) => array_column(json_decode($r->getContent(), true)['presentations'], 'titre');
        $this->assertSame(['A', 'Nouvelle', 'B'], $titles($response));
        $new = (int) $this->db()->fetchOne("SELECT id FROM presentation WHERE titre = 'Nouvelle'");
        $this->assertSame('<p>Texte</p>', $this->translation($new, 'fr')['texte']);
        $this->assertSame(422, $this->request('POST', "/api/presentation-groups/$group/presentations", json_encode(['texte' => 'sans titre']))->getStatusCode());
        $this->assertSame(422, $this->request('POST', "/api/presentation-groups/$group/presentations", json_encode(['titre' => 'X', 'after' => 999999]))->getStatusCode());

        // ordre : toutes les présentations du groupe, une fois chacune
        $response = $this->request('PUT', "/api/presentation-groups/$group/presentations/order", json_encode(['order' => [$b, $new, $a]]));
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertSame(['B', 'Nouvelle', 'A'], $titles($response));
        $this->assertSame(['B', 'Nouvelle', 'A'], $titles($this->request('GET', "/api/presentation-groups/$group")), 'lecture publique dans le même ordre');
        $this->assertSame(422, $this->request('PUT', "/api/presentation-groups/$group/presentations/order", json_encode(['order' => [$b, $a]]))->getStatusCode(), 'il en manque une');
        $this->assertSame(400, $this->request('PUT', "/api/presentation-groups/$group/presentations/order", json_encode(['order' => 'b,a']))->getStatusCode());

        // retrait : A n'appartient à aucun autre groupe (supprimée), B est partagée (seulement détachée)
        $this->assertSame(['B', 'Nouvelle'], $titles($this->request('DELETE', "/api/presentation-groups/$group/presentations/$a")));
        $this->assertFalse($this->db()->fetchOne('SELECT id FROM presentation WHERE id = ?', [$a]));
        $this->assertSame(['Nouvelle'], $titles($this->request('DELETE', "/api/presentation-groups/$group/presentations/$b")));
        $this->assertSame($b, (int) $this->db()->fetchOne('SELECT id FROM presentation WHERE id = ?', [$b]), 'encore dans l\'autre groupe');
        $this->assertSame(404, $this->request('DELETE', "/api/presentation-groups/$group/presentations/$b")->getStatusCode());
        $this->assertSame(404, $this->request('POST', '/api/presentation-groups/999999/presentations', json_encode(['titre' => 'X']))->getStatusCode());

        $this->session = $this->login(['ROLE_USER_INTERNET']);
        $this->assertSame(403, $this->request('POST', "/api/presentation-groups/$group/presentations", json_encode(['titre' => 'Pirate']))->getStatusCode());
    }

    public function testCompanyLegalTextsAreFilteredButUnchangedLegacyTextIsTolerated(): void
    {
        $id = (int) $this->db()->fetchOne('SELECT MIN(id) FROM entreprise');
        $legacy = '<p className="x">Ancien texte <a href="https://x.y" target="_blank">lien</a></p>';
        $this->db()->executeStatement('DELETE FROM entreprise_translation WHERE entreprise_id = ?', [$id]);
        $this->db()->executeStatement("INSERT INTO entreprise_translation (id, entreprise_id, language, legal_notice) VALUES (nextval('entreprise_translation_id_seq'), ?, 'fr', ?)", [$id, $legacy]);

        $refused = $this->request('PUT', "/api/entreprise/$id?locale=fr", json_encode(['privacyPolicy' => '<p style="color:red" onclick="x">a</p>', 'apropos' => '<a href="javascript:alert(1)">x</a>']));
        $this->assertSame(422, $refused->getStatusCode(), $refused->getContent());
        $this->assertEqualsCanonicalizing(['privacyPolicy', 'apropos'], array_unique(array_column(json_decode($refused->getContent(), true)['errors'], 'path')));
        $this->assertNull($this->db()->fetchOne('SELECT privacy_policy FROM entreprise_translation WHERE entreprise_id = ?', [$id]), 'rien n\'est écrit');

        $ok = $this->request('PUT', "/api/entreprise/$id?locale=fr", json_encode([
            'LegalNotice' => $legacy, // renvoyé tel quel : toléré
            'conditionOfUse' => '<h2>Conditions</h2><table><tr><th colspan="2">A</th></tr><tr><td>1</td><td>2</td></tr></table><p><a href="mailto:a@b.c">écrire</a></p>',
        ]));
        $this->assertSame(200, $ok->getStatusCode(), $ok->getContent());
        $this->assertSame(422, $this->request('PUT', "/api/entreprise/$id?locale=fr", json_encode(['LegalNotice' => $legacy . ' modifié']))->getStatusCode(), 'modifié : contrôlé');
    }

    public function testEditsAreJournaledAndCanBeRestored(): void
    {
        $id = $this->presentation('Titre d\'origine', ['fr' => 'Titre d\'origine']);
        $this->assertSame(200, $this->patch("/api/presentations/$id?locale=fr", ['titre' => 'Titre erroné', 'texteBouton' => 'Écrire'])->getStatusCode());

        $list = json_decode($this->request('GET', "/api/landingpage-audit?resource=presentations&resourceId=$id")->getContent(), true);
        $this->assertSame(1, $list['total']);
        $entry = $list['items'][0];
        $this->assertSame(['presentations', (string) $id, 'update', 'fr', ['titre', 'texteBouton'], true], [$entry['resource'], $entry['resourceId'], $entry['action'], $entry['locale'], $entry['fields'], $entry['restorable']]);
        $this->assertStringStartsWith('landing-content-', $entry['user']);
        $detail = json_decode($this->request('GET', '/api/landingpage-audit/' . $entry['id'])->getContent(), true);
        $this->assertSame(['titre' => 'Titre d\'origine', 'texteBouton' => null], $detail['before']);
        $this->assertSame(['titre' => 'Titre erroné', 'texteBouton' => 'Écrire'], $detail['after']);

        // retour en arrière, lui-même journalisé
        $restored = $this->request('POST', '/api/landingpage-audit/' . $entry['id'] . '/restore');
        $this->assertSame(200, $restored->getStatusCode(), $restored->getContent());
        $body = json_decode($restored->getContent(), true);
        $this->assertSame('Titre d\'origine', $body['result']['titre']);
        $this->assertSame(['restore', $entry['id']], [$body['entry']['action'], $body['entry']['restoredFrom']]);
        $this->assertSame('Titre d\'origine', $this->db()->fetchOne('SELECT titre FROM presentation WHERE id = ?', [$id]));

        // la ressource a changé depuis : refus, sauf confirmation
        $this->assertSame(409, $this->request('POST', '/api/landingpage-audit/' . $entry['id'] . '/restore')->getStatusCode());
        $this->assertSame(200, $this->request('POST', '/api/landingpage-audit/' . $body['entry']['id'] . '/restore')->getStatusCode(), 'annuler le retour en arrière');
        $this->assertSame('Titre erroné', $this->db()->fetchOne('SELECT titre FROM presentation WHERE id = ?', [$id]));
    }

    public function testSettingsAndMediaWritesAreJournaled(): void
    {
        $settings = $this->db()->fetchOne('SELECT configuration::text FROM landing_page_setting ORDER BY id LIMIT 1');
        try {
            $this->assertSame(200, $this->request('PUT', '/api/landingpage-settings', json_encode(['configuration' => ['tabs' => [['name' => 'Accueil', 'sections' => []]]]]))->getStatusCode());
            $entry = json_decode($this->request('GET', '/api/landingpage-audit?resource=landingpage-settings')->getContent(), true)['items'][0];
            $this->assertSame(['update', true], [$entry['action'], $entry['restorable']]);
            $this->assertContains('tabs', $entry['fields']);
            $this->assertSame(200, $this->request('POST', '/api/landingpage-audit/' . $entry['id'] . '/restore')->getStatusCode());
            $this->assertEquals(json_decode((string) $settings, true), json_decode($this->db()->fetchOne('SELECT configuration::text FROM landing_page_setting ORDER BY id LIMIT 1'), true), 'réglages rétablis');
        } finally {
            $this->db()->executeStatement('UPDATE landing_page_setting SET configuration = ?::json WHERE id = (SELECT MIN(id) FROM landing_page_setting)', [$settings ?: '{}']);
        }

        $upload = json_decode($this->upload('photo.png', $this->png())->getContent());
        $this->files[] = static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/private_media/' . $this->db()->fetchOne('SELECT filename FROM shared_media WHERE id = ?', [$upload->id]);
        $entry = json_decode($this->request('GET', '/api/landingpage-audit?resource=media')->getContent(), true)['items'][0];
        $this->assertSame(['upload', false], [$entry['action'], $entry['restorable']]);
        $this->assertSame(409, $this->request('POST', '/api/landingpage-audit/' . $entry['id'] . '/restore')->getStatusCode(), 'un téléversement ne s\'annule pas');

        $this->assertSame(400, $this->request('GET', '/api/landingpage-audit?limit=500')->getStatusCode());
        $this->assertSame(404, $this->request('GET', '/api/landingpage-audit/999999')->getStatusCode());
        $this->session = $this->login(['ROLE_USER_INTERNET']);
        $this->assertSame(403, $this->request('GET', '/api/landingpage-audit')->getStatusCode());
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
