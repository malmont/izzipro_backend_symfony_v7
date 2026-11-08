<?php

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Services\LandingAiService\LandingAiCatalogue;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeLandingAiClient;
use App\Tests\Functional\Support\OtpTestHelper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * « Mon site pas à pas » (08/11/2026) : le questionnaire refuse une réponse fausse sans rien écrire, enregistre le brief, la charte et le
 * PDF, montre le résumé et l'aiguillage ; « Composer mon site » lance la composition du site complet (la charte du site est redonnée à
 * l'assistant), l'écran d'attente suit les pages, « Appliquer » écrit les onglets dans la configuration de la landing page et le
 * cheminement passe « Pages du site » et « brief » à fait.
 */
class SiteSetupQuestionnaireTest extends WebTestCase
{
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $landingBefore = null;
    private ?string $boutiqueBefore = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->setServerParameter('HTTP_X_TENANT_HOST', MV_TEST_TENANT_HOST);
        FakeLandingAiClient::reset();
        static::getContainer()->get('limiter.landing_ai_tenant')->create('tenant:' . MV_TEST_TENANT_CODE)->reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        $this->db->executeStatement('DELETE FROM site_charter');
        $this->db->executeStatement('DELETE FROM ai_job');
        $this->db->executeStatement('DELETE FROM ai_usage');
        $this->db->executeStatement('DELETE FROM ai_credit_setting');
        // Le site de test n'a que « IA complète » : la landing page est accordée le temps du test (étape « Pages du site »)
        $master = static::getContainer()->get(\App\Services\TenantConnectionManager::class)->getPdoMaster();
        $master->prepare("DELETE FROM tenant_entitlements WHERE tenant_code = ? AND feature_key = 'module.landing'")->execute([MV_TEST_TENANT_CODE]);
        $master->prepare("INSERT INTO tenant_entitlements (tenant_code, feature_key, source, status, granted_by, note) VALUES (?, 'module.landing', 'manual', 'active', 'test', 'questionnaire')")->execute([MV_TEST_TENANT_CODE]);
        $master->prepare("DELETE FROM tenant_entitlements WHERE tenant_code = ? AND feature_key = 'module.boutique' AND granted_by = 'test'")->execute([MV_TEST_TENANT_CODE]);
        $master->prepare("INSERT INTO tenant_entitlements (tenant_code, feature_key, source, status, granted_by, note) SELECT ?, 'module.boutique', 'manual', 'active', 'test', 'questionnaire' WHERE NOT EXISTS (SELECT 1 FROM tenant_entitlements WHERE tenant_code = ? AND feature_key = 'module.boutique' AND status = 'active')")->execute([MV_TEST_TENANT_CODE, MV_TEST_TENANT_CODE]);
        $raw = $this->db->fetchOne('SELECT configuration::text FROM landing_page_setting ORDER BY id LIMIT 1');
        $this->landingBefore = is_string($raw) ? $raw : null;
        $raw = $this->db->fetchOne('SELECT configuration::text FROM boutique_setting ORDER BY id LIMIT 1');
        $this->boutiqueBefore = is_string($raw) ? $raw : null;
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
    }

    protected function tearDown(): void
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $db = $provider->getEntityManager()->getConnection();
        if ($this->landingBefore !== null) {
            $db->executeStatement('UPDATE landing_page_setting SET configuration = ?', [$this->landingBefore]);
        }
        if ($this->boutiqueBefore !== null) {
            $db->executeStatement('UPDATE boutique_setting SET configuration = ?', [$this->boutiqueBefore]);
        }
        $db->executeStatement('DELETE FROM site_charter');
        $db->executeStatement('DELETE FROM ai_job');
        $db->executeStatement('DELETE FROM ai_usage');
        $db->executeStatement("DELETE FROM presentation WHERE titre = 'Exemple questionnaire test'");
        $db->executeStatement('UPDATE presentation SET is_demo = FALSE');
        static::getContainer()->get(\App\Services\TenantConnectionManager::class)->getPdoMaster()->prepare("DELETE FROM tenant_entitlements WHERE tenant_code = ? AND feature_key IN ('module.landing', 'module.boutique') AND granted_by = 'test'")->execute([MV_TEST_TENANT_CODE]);
        \App\Tests\Functional\Support\AiRightTestHelper::restoreFullAi(static::getContainer());
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        parent::tearDown();
    }

    public function testTheQuestionnaireSavesComposesAndAppliesTheSite(): void
    {
        $url = 'https://' . MV_TEST_TENANT_HOST . '/admin/site/configurer';
        $crawler = $this->client->request('GET', $url);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(1, $crawler->filter('[data-site-setup]')->count());
        $this->assertStringContainsString('Que faites-vous', (string) $this->client->getResponse()->getContent());
        $this->assertSame(1, $crawler->filter('[data-site-routing]')->count(), 'aiguillage « où se fait quoi »');
        $token = $crawler->filter('[data-site-setup-form] input[name="_token"]')->attr('value');

        // Une couleur fausse : rien d'écrit
        $this->client->request('POST', $url, ['_token' => $token, 'activity' => 'Atelier de réparation de vélos', 'primaryColor' => 'bleu', 'pages' => ['Services'], 'salesModes' => ['sale']]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('data-error="primaryColor"', (string) $this->client->getResponse()->getContent());
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM site_charter WHERE brief IS NOT NULL'));

        // Réponses complètes, avec un PDF de charte
        $pdf = tempnam(sys_get_temp_dir(), 'charte') . '.pdf';
        file_put_contents($pdf, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
        $this->client->request('POST', $url, [
            '_token' => $token, 'activity' => 'Atelier de réparation de vélos à Québec', 'audience' => 'cyclistes du quotidien', 'place' => 'Québec',
            'goals' => ['found', 'book'], 'pages' => ['Services', 'Contact'], 'pagesExtra' => 'Galerie', 'tone' => 'sobre', 'motion' => 'discret', 'density' => 'normale',
            'primaryColor' => '#1d4ed8', 'headingFont' => 'Outfit', 'buttonStyle' => 'pill', 'radius' => '8', 'designMd' => 'Charte test ABC : bleu profond, titres Outfit.',
            'sells' => 'vélos reconditionnés', 'salesModes' => ['sale', 'rental'], 'handover' => ['pickup'], 'replaceDemo' => '1',
        ], ['document' => new UploadedFile($pdf, 'charte.pdf', 'application/pdf', null, true)]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $row = $this->db->fetchAssociative('SELECT brief::text AS brief, charter::text AS charter, style::text AS style, design_md, document_name FROM site_charter ORDER BY id LIMIT 1');
        $brief = json_decode((string) $row['brief'], true);
        $this->assertSame(['Services', 'Contact', 'Galerie'], $brief['pages']);
        $this->assertSame(['found', 'book'], $brief['goals']);
        $this->assertTrue($brief['replaceDemo'], 'remplacer le contenu d\'exemple (lot 3)');
        $this->assertSame('#1d4ed8', json_decode((string) $row['charter'], true)['primaryColor']);
        $this->assertSame(8, json_decode((string) $row['charter'], true)['radius']);
        $this->assertSame('sobre', json_decode((string) $row['style'], true)['tone']);
        $this->assertStringContainsString('Charte test ABC', (string) $row['design_md']);
        $this->assertSame('charte.pdf', $row['document_name']);

        $crawler = $this->client->request('GET', $url);
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Galerie', $html, 'page ajoutée reprise');
        $this->assertStringContainsString('4 page(s) dans le menu', $html);
        $this->assertStringContainsString('Document de charte joint', $html);
        $this->assertStringContainsString('data-document-name', $html);

        // Cheminement : brief fait, pages du site encore à faire (contenu d'exemple)
        $readiness = $this->readiness();
        $this->assertSame('done', $readiness['site.brief']['status']);
        $this->assertSame('todo', $readiness['landing.pages']['status'], 'les pages du modèle ne comptent pas');
        $this->assertSame('site_setup', $readiness['landing.pages']['action']['screen']);

        // Composer : accueil puis 3 pages. Contenu d'exemple (lot 3) : la présentation affichée par l'accueil est gardée, l'autre effacée
        $catalogue = static::getContainer()->get(LandingAiCatalogue::class);
        $preset = fn (string $id) => json_decode(json_encode($catalogue->preset($id)[1]['composition'], JSON_PRESERVE_ZERO_FRACTION), false);
        $presentationId = (int) static::getContainer()->get(\App\Services\LandingAiService\LandingAiDataSources::class)->availableIds('Presentation')[0];
        $this->db->executeStatement('UPDATE presentation SET is_demo = TRUE WHERE id = ?', [$presentationId]);
        $this->db->executeStatement("INSERT INTO presentation (id, titre, texte, is_demo) VALUES (nextval('presentation_id_seq'), 'Exemple questionnaire test', 'x', TRUE)");
        $unusedDemoId = (int) $this->db->fetchOne("SELECT MAX(id) FROM presentation WHERE titre = 'Exemple questionnaire test'");
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        $this->assertStringContainsString('sera effacé quand vous appliquerez', (string) $this->client->request('GET', $url)->html(), 'le résumé annonce le remplacement');
        $home = FakeLandingAiClient::pageResponse([
            ['componentKey' => 'Navbar', 'dataType' => null, 'composition' => $preset('boutique-navbar-type-a')],
            ['componentKey' => 'Presentation', 'dataType' => $presentationId, 'composition' => $preset('presentation-type-b')],
            ['componentKey' => 'Footer', 'dataType' => null, 'composition' => $preset('boutique-footer-type-a')],
        ], 'Accueil composé.');
        $home->content[0]->input->charter = (object) ['primaryColor' => '#FFA552', 'buttonStyle' => 'pill'];
        FakeLandingAiClient::$queue[] = $home;

        $composeToken = $crawler->filter('[data-compose-form] input[name="_token"]')->attr('value');
        $this->client->request('POST', $url . '/composer', ['_token' => $composeToken]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $location = (string) $this->client->getResponse()->headers->get('Location');
        $this->assertSame(1, preg_match('/([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})/', $location, $m), $location);
        $this->assertStringContainsString('admin_site_configurer_composition', $location, 'écran d\'attente habillé par EasyAdmin');
        $compositionUrl = 'https://' . MV_TEST_TENANT_HOST . '/admin/site/configurer/composition/' . $m[1]; // la route nue sert aussi (sans habillage)
        $messages = $this->sentJobMessages();
        $this->assertCount(1, $messages, 'l\'accueil part au worker');

        $this->runWorker($messages);
        $request = json_encode(FakeLandingAiClient::$requests[0], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Charte test ABC', $request, 'le DESIGN.md du site est redonné à l\'assistant sans que la demande le porte');
        $this->assertStringContainsString('Atelier de réparation de vélos', $request, 'le brief est le prompt');
        $this->assertStringContainsString('#1d4ed8', $request, 'la charte du site prime');
        $this->assertStringContainsString('document PDF', $request, 'le PDF de charte est joint');
        $children = array_values(array_filter($this->sentJobMessages(), fn ($m) => $m->jobId !== $messages[0]->jobId)); // le transport garde aussi l'accueil
        $this->assertCount(3, $children, 'une tâche par page voulue');

        $this->client->request('GET', $compositionUrl);
        $this->assertStringContainsString('data-status="running"', (string) $this->client->getResponse()->getContent());

        foreach ($children as $child) {
            FakeLandingAiClient::$queue[] = FakeLandingAiClient::pageResponse([['componentKey' => 'Contact', 'dataType' => null, 'composition' => $preset('contact-type-a')]], 'Page composée.');
        }
        $this->runWorker($children);

        $crawler = $this->client->request('GET', $compositionUrl);
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('data-status="done"', $html);
        $this->assertStringContainsString('Galerie', $html);
        $this->assertSame(1, $crawler->filter('[data-apply-form]')->count());
        $applyToken = $crawler->filter('[data-apply-form] input[name="_token"]')->attr('value');

        $this->client->request('POST', $compositionUrl . '/appliquer', ['_token' => $applyToken]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM presentation WHERE id = ?', [$unusedDemoId]), 'fiche d\'exemple non affichée : effacée');
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM presentation WHERE id = ? AND is_demo', [$presentationId]), 'fiche d\'exemple affichée par l\'accueil : gardée, toujours marquée');
        $this->db->executeStatement('UPDATE presentation SET is_demo = FALSE WHERE id = ?', [$presentationId]);
        $config = json_decode((string) $this->db->fetchOne('SELECT configuration::text FROM landing_page_setting ORDER BY id LIMIT 1'), true);
        $this->assertSame(['Accueil', 'Services', 'Contact', 'Galerie'], array_map(fn ($t) => $t['title']['fr'], $config['tabs']));
        $this->assertSame(['Presentation'], array_column($config['tabs'][0]['sections'], 'componentKey'), 'navbar et footer à part');
        $this->assertSame('typeReglable', $config['tabs'][0]['sections'][0]['componentTypeKey']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $config['tabs'][0]['sections'][0]['uid']);
        $this->assertSame('typeReglable', $config['navbar']['componentTypeKey']);
        $this->assertArrayHasKey('reglableConfig', $config['footer']);
        $this->assertSame(['Contact'], array_column($config['tabs'][3]['sections'], 'componentKey'));
        $after = $this->db->fetchAssociative('SELECT composed_at, pages_saved_at, charter::text AS charter FROM site_charter ORDER BY id LIMIT 1');
        $this->assertNotNull($after['composed_at']);
        $this->assertNotNull($after['pages_saved_at']);
        $this->assertSame('#1d4ed8', json_decode((string) $after['charter'], true)['primaryColor'], 'la charte donnée par l\'administrateur n\'est pas écrasée par celle de l\'assistant');

        $this->client->request('GET', $compositionUrl);
        $this->assertStringContainsString('data-applied', (string) $this->client->getResponse()->getContent());
        $readiness = $this->readiness();
        $this->assertSame('done', $readiness['landing.pages']['status'], 'pages composées et appliquées');
        @unlink($pdf);
    }

    public function testTheShopPagesAreComposedAndApplied(): void
    {
        $url = 'https://' . MV_TEST_TENANT_HOST . '/admin/site/configurer';
        $crawler = $this->client->request('GET', $url);
        $this->assertSame(1, $crawler->filter('[data-compose-shop-form]')->count(), 'bouton « Composer les pages de ma boutique » (boutique accordée)');
        $token = $crawler->filter('[data-site-setup-form] input[name="_token"]')->attr('value');
        $this->client->request('POST', $url, ['_token' => $token, 'activity' => 'Boutique de vélos reconditionnés', 'pages' => ['Contact'], 'salesModes' => ['sale'], 'sells' => 'vélos reconditionnés']);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());

        $catalogue = static::getContainer()->get(LandingAiCatalogue::class);
        $preset = fn (string $id) => json_decode(json_encode($catalogue->preset($id)[1]['composition'], JSON_PRESERVE_ZERO_FRACTION), false);
        $presentationId = (int) static::getContainer()->get(\App\Services\LandingAiService\LandingAiDataSources::class)->availableIds('Presentation')[0];
        $home = FakeLandingAiClient::pageResponse([
            ['componentKey' => 'Navbar', 'dataType' => null, 'composition' => $preset('boutique-navbar-type-a')],
            ['componentKey' => 'Presentation', 'dataType' => $presentationId, 'composition' => $preset('presentation-type-b')],
            ['componentKey' => 'Footer', 'dataType' => null, 'composition' => $preset('boutique-footer-type-a')],
        ], 'Accueil de la boutique composé.');
        $home->content[0]->input->charter = (object) ['primaryColor' => '#FFA552', 'buttonStyle' => 'pill'];
        FakeLandingAiClient::$queue[] = $home;

        $crawler = $this->client->request('GET', $url);
        $shopToken = $crawler->filter('[data-compose-shop-form] input[name="_token"]')->attr('value');
        $this->client->request('POST', $url . '/composer-boutique', ['_token' => $shopToken, 'shopPages' => ['login', 'checkout']]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->assertSame(1, preg_match('/([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})/', (string) $this->client->getResponse()->headers->get('Location'), $m));
        $compositionUrl = 'https://' . MV_TEST_TENANT_HOST . '/admin/site/configurer/composition/' . $m[1];
        $messages = $this->sentJobMessages();
        $this->assertCount(1, $messages);
        $this->runWorker($messages);
        $this->assertStringContainsString('Pages de la boutique en ligne', json_encode(FakeLandingAiClient::$requests[0], JSON_UNESCAPED_UNICODE));
        $children = array_values(array_filter($this->sentJobMessages(), fn ($msg) => $msg->jobId !== $messages[0]->jobId));
        $this->assertCount(2, $children, 'connexion et paiement');
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::pageResponse([['componentKey' => 'AuthPage', 'dataType' => null, 'composition' => $preset('auth-login')]]);
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::pageResponse([['componentKey' => 'Checkout', 'dataType' => null, 'composition' => $preset('checkout-type-a')]]);
        $this->runWorker($children);

        $crawler = $this->client->request('GET', $compositionUrl);
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('data-status="done"', $html);
        $this->assertStringContainsString('data-app="boutique"', $html);
        $this->assertStringContainsString('Connexion', $html, 'titre de la page système');
        $this->assertStringContainsString('Appliquer sur ma boutique', $html);
        $applyToken = $crawler->filter('[data-apply-form] input[name="_token"]')->attr('value');
        $this->client->request('POST', $compositionUrl . '/appliquer', ['_token' => $applyToken]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());

        $config = json_decode((string) $this->db->fetchOne('SELECT configuration::text FROM boutique_setting ORDER BY id LIMIT 1'), true);
        $bySystem = [];
        foreach ($config['tabs'] as $tab) {
            $bySystem[($tab['system'] ?? '') . (isset($tab['variant']) ? '/' . $tab['variant'] : '')] = $tab;
        }
        $this->assertSame(['Presentation'], array_column($bySystem['']['sections'], 'componentKey'), 'accueil de la boutique');
        $this->assertSame(['AuthPage'], array_column($bySystem['login']['sections'], 'componentKey'));
        $this->assertSame(['Checkout'], array_column($bySystem['checkout']['sections'], 'componentKey'));
        $this->assertSame('typeReglable', $config['navbar']['componentTypeKey']);
        $this->assertSame('#FFA552', $config['charter']['primaryColor'] ?? null, 'charte déduite posée sur la boutique si elle n\'en avait pas');
        $this->assertNotNull($this->db->fetchOne('SELECT shop_composed_at FROM site_charter ORDER BY id LIMIT 1'));
        $this->assertNull($this->db->fetchOne('SELECT composed_at FROM site_charter ORDER BY id LIMIT 1'), 'les pages de la landing page ne sont pas marquées composées');
        $this->assertStringContainsString('data-applied', (string) $this->client->request('GET', $compositionUrl)->html());
    }

    /** @return array<string, array<string, mixed>> étapes par clé */
    private function readiness(): array
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();

        return array_column(static::getContainer()->get(\App\Services\Platform\SiteReadinessService::class)->forSite(MV_TEST_TENANT_CODE)['steps'], null, 'key');
    }

    /** @return list<\App\Message\LandingAiJobMessage> */
    private function sentJobMessages(): array
    {
        return array_values(array_filter(
            array_map(fn ($envelope) => $envelope->getMessage(), static::getContainer()->get('messenger.transport.landing_ai')->getSent()),
            fn ($message) => $message instanceof \App\Message\LandingAiJobMessage
        ));
    }

    private function runWorker(array $messages): void
    {
        $handler = static::getContainer()->get(\App\MessageHandler\LandingAiJobHandler::class);
        foreach ($messages as $message) {
            $handler($message);
        }
    }

    private function loginAs(array $roles): void
    {
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $email = 'site-setup-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Site')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'Mot-de-passe-de-test-1!'));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);
    }
}
