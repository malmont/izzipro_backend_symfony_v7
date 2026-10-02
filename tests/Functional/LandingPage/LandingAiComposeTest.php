<?php

namespace App\Tests\Functional\LandingPage;

use App\Entity\User;
use App\Services\LandingAiService\LandingAiCatalogue;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeLandingAiClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Process\Process;

/**
 * Assistant IA de l'éditeur (étape 1 : retouche), avec le client Anthropic simulé : aucun appel réel.
 */
class LandingAiComposeTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';

    private KernelBrowser $client;
    private array $session = [];
    /** Tenant des requêtes : [hôte, base, code] */
    private array $tenant = [MV_TEST_TENANT_HOST, MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeLandingAiClient::reset();
        static::getContainer()->get('limiter.landing_ai_tenant')->create('tenant:' . MV_TEST_TENANT_CODE)->reset();
        $this->db()->executeStatement('DELETE FROM ai_usage');
        $this->db()->executeStatement('DELETE FROM ai_job');
        $this->db()->executeStatement('DELETE FROM ai_credit_setting');
        $this->session = $this->login(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
    }

    public function testEditAppliesOperationsAndLeavesOtherBlocksIdentical(): void
    {
        $settingsBefore = $this->get('/api/landingpage-settings')->getContent();
        $before = $this->preset('group-type-t');
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::editResponse([
            ['op' => 'update', 'id' => 't-titre', 'set' => ['color' => '#1B5FE6', 'translations' => new \stdClass()], 'unset' => ['letterSpacing']],
            ['op' => 'add', 'after' => 't-titre', 'block' => ['id' => 't-sous-titre', 'type' => 'text', 'parentId' => null, 'text' => 'Nos offres', 'lineHeight' => 1.0]],
            ['op' => 'section', 'set' => ['rootGap' => 64]],
        ], 'Titre recoloré, sous-titre ajouté.');

        $response = $this->compose(['mode' => 'edit', 'componentKey' => 'PresentationGroup', 'composition' => $before, 'prompt' => 'Recolore le titre et ajoute un sous-titre.']);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), false);
        $after = $this->blocksById($body->composition);
        $this->assertSame('#1B5FE6', $after['t-titre']->color);
        $this->assertFalse(property_exists($after['t-titre'], 'letterSpacing'));
        $this->assertEquals(new \stdClass(), $after['t-titre']->translations, '{} reste un objet');
        $this->assertEquals((object) ['text' => 'title'], $after['t-titre']->bindings, 'liaisons non visées conservées');
        $this->assertStringContainsString('"lineHeight":1.0', $response->getContent(), '1.0 reste un décimal');
        $this->assertSame(64, $body->composition->rootGap);
        $ids = array_column(array_map(fn ($b) => (array) $b, $body->composition->blocks), 'id');
        $this->assertSame(array_search('t-titre', $ids) + 1, array_search('t-sous-titre', $ids), 'bloc inséré après t-titre');
        foreach ($this->blocksById($before) as $id => $block) {
            if ($id !== 't-titre') {
                $this->assertSame(json_encode($block), json_encode($after[$id]), "bloc $id strictement identique");
            }
        }
        $this->assertSame('Titre recoloré, sous-titre ajouté.', $body->summary);
        $this->assertSame(['monthly' => 100, 'used' => 1, 'remaining' => 99], array_intersect_key((array) $body->credits, array_flip(['monthly', 'used', 'remaining'])));
        $this->assertSame(1, $body->usage->attempts);
        $this->assertSame('claude-sonnet-5', $body->usage->model);

        // Requête envoyée au modèle : outil imposé, parties fixes en cache, données du site encadrées
        $request = FakeLandingAiClient::$requests[0];
        $this->assertSame(['type' => 'tool', 'name' => 'retoucher_composition'], $request['tool_choice']);
        $this->assertSame('ephemeral', $request['system'][1]['cache_control']['type']);
        $this->assertStringContainsString('<donnees_du_site>', $request['messages'][0]['content']);
        $this->assertStringContainsString('Recolore le titre', $request['messages'][0]['content']);

        // Jamais d'écriture des réglages du site, historique « success »
        $this->assertSame($settingsBefore, $this->get('/api/landingpage-settings')->getContent());
        $history = json_decode($this->get('/api/landingpage-ai/usage')->getContent(), true)['history'];
        $this->assertSame('success', $history[0]['status']);
        $this->assertSame('Recolore le titre et ajoute un sous-titre.', $history[0]['promptExcerpt']);
    }

    public function testRemoveAlsoRemovesDescendants(): void
    {
        $before = $this->preset('group-type-t');
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::editResponse([['op' => 'remove', 'id' => 't-web']]);

        $response = $this->compose(['mode' => 'edit', 'componentKey' => 'PresentationGroup', 'composition' => $before, 'prompt' => 'Retire l\'onglet Développement Web.']);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $after = $this->blocksById(json_decode($response->getContent(), false)->composition);
        foreach (['t-web', 't-web-1', 't-web-1-titre', 't-web-4-texte'] as $id) {
            $this->assertArrayNotHasKey($id, $after, "$id retiré");
        }
        $this->assertArrayHasKey('t-app', $after);
    }

    public function testInvalidThenValidSucceedsOnSecondAttemptWithErrorsSentBack(): void
    {
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::editResponse([['op' => 'update', 'id' => 't-titre', 'set' => ['fontColor' => '#000000']]]);
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::editResponse([['op' => 'update', 'id' => 't-titre', 'set' => ['color' => '#000000']]]);

        $response = $this->compose(['mode' => 'edit', 'componentKey' => 'PresentationGroup', 'composition' => $this->preset('group-type-t'), 'prompt' => 'Titre en noir.']);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertSame(2, json_decode($response->getContent())->usage->attempts);
        $this->assertEqualsWithDelta(\App\Services\LandingAiService\LandingAiComposer::CALL_TIMEOUT, FakeLandingAiClient::$timeouts[0], 1.0, 'retouche : 90 s');
        $retry = FakeLandingAiClient::$requests[1]['messages'];
        $this->assertSame('assistant', $retry[1]['role']);
        $this->assertSame('tool_result', $retry[2]['content'][0]['type']);
        $this->assertTrue($retry[2]['content'][0]['is_error']);
        $this->assertStringContainsString('fontColor', $retry[2]['content'][0]['content']);
    }

    public function testThreeInvalidResponsesGive502AndReleaseCredits(): void
    {
        for ($i = 0; $i < 3; $i++) {
            FakeLandingAiClient::$queue[] = FakeLandingAiClient::editResponse([['op' => 'update', 'id' => 'inconnu', 'set' => ['color' => '#000000']]]);
        }

        $response = $this->compose(['mode' => 'edit', 'componentKey' => 'PresentationGroup', 'composition' => $this->preset('group-type-t'), 'prompt' => 'Titre en noir.']);

        $this->assertSame(502, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->assertSame('operations[0].id', $body['errors'][0]['path']);
        $usage = json_decode($this->get('/api/landingpage-ai/usage')->getContent(), true);
        $this->assertSame(0, $usage['credits']['used'], 'crédits libérés');
        $this->assertSame('failed', $usage['history'][0]['status']);
        $this->assertSame(3, $usage['history'][0]['attempts']);
    }

    public function testUnauthorizedMediaTriggersAnotherAttempt(): void
    {
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::editResponse([['op' => 'section', 'set' => ['bgImage' => 'https://images.example.com/inventee.jpg']]]);
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::editResponse([], 'Aucune image disponible.', ['Aucune image de fond fournie : filtre non ajouté.']);

        $response = $this->compose(['mode' => 'edit', 'componentKey' => 'PresentationGroup', 'composition' => $this->preset('group-type-t'), 'prompt' => 'Ajoute une image de fond.']);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertStringContainsString('bgImage', FakeLandingAiClient::$requests[1]['messages'][2]['content'][0]['content']);
        $this->assertSame(['Aucune image de fond fournie : filtre non ajouté.'], json_decode($response->getContent(), true)['warnings']);
    }

    public function testMediaProvidedWithTheRequestIsAllowed(): void
    {
        $key = str_repeat('ab', 32);
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::editResponse([['op' => 'section', 'set' => ['bgImage' => 'https://cdn.example.com/equipe.jpg']]]);

        $response = $this->compose(['mode' => 'edit', 'componentKey' => 'PresentationGroup', 'composition' => $this->preset('group-type-t'), 'prompt' => 'Mets cette photo en fond.',
            'media' => [['kind' => 'image', 'url' => 'https://cdn.example.com/equipe.jpg', 'mediaKey' => null, 'label' => 'équipe'], ['kind' => 'image', 'url' => null, 'mediaKey' => $key]]]);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertCount(1, FakeLandingAiClient::$requests);
    }

    public function testBlockTypeOutsideTheFamilyTriggersAnotherAttempt(): void
    {
        // Famille Contact : pas de bloc vidéo
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::editResponse([['op' => 'add', 'block' => ['id' => 'c-video', 'type' => 'video', 'parentId' => null]]]);
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::editResponse([['op' => 'update', 'id' => 'a-titre', 'set' => ['size' => 34]]]);

        $response = $this->compose(['mode' => 'edit', 'componentKey' => 'Contact', 'composition' => $this->preset('contact-type-a'), 'prompt' => 'Ajoute une vidéo.']);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertStringContainsString('non disponible pour cette famille', FakeLandingAiClient::$requests[1]['messages'][2]['content'][0]['content']);
    }

    public function testQuotaExhaustedGives402WithoutCallingTheAi(): void
    {
        $this->db()->executeStatement('INSERT INTO ai_credit_setting (monthly_credits) VALUES (1)');
        $this->db()->executeStatement("INSERT INTO ai_usage (tenant, created_at, mode, component_key, status, credits) VALUES ('mvtest', NOW(), 'edit', 'PresentationGroup', 'success', 1)");

        $response = $this->compose(['mode' => 'edit', 'componentKey' => 'PresentationGroup', 'composition' => $this->preset('group-type-t'), 'prompt' => 'Titre en noir.']);

        $this->assertSame(402, $response->getStatusCode(), $response->getContent());
        $this->assertSame([], FakeLandingAiClient::$requests);
    }

    public function testOrphanReservationIsReleasedAfterFiveMinutes(): void
    {
        $this->db()->executeStatement('INSERT INTO ai_credit_setting (monthly_credits) VALUES (1)');
        $this->db()->executeStatement("INSERT INTO ai_usage (tenant, created_at, reserved_until, mode, component_key, status, credits) VALUES ('mvtest', NOW() - INTERVAL '6 minutes', NOW() - INTERVAL '1 minute', 'edit', 'PresentationGroup', 'reserved', 1)");
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::editResponse([]);

        $response = $this->compose(['mode' => 'edit', 'componentKey' => 'PresentationGroup', 'composition' => $this->preset('group-type-t'), 'prompt' => 'Rien à changer.']);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertSame('expired', $this->db()->fetchOne("SELECT status FROM ai_usage WHERE created_at < NOW() - INTERVAL '5 minutes'"));
    }

    public function testSixthRequestInTheSameMinuteGives429(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            FakeLandingAiClient::$queue[] = FakeLandingAiClient::editResponse([]);
            $response = $this->compose(['mode' => 'edit', 'componentKey' => 'PresentationGroup', 'composition' => $this->preset('group-type-t'), 'prompt' => "Demande $i"]);
            $this->assertSame(200, $response->getStatusCode(), "demande $i : " . $response->getContent());
        }

        $response = $this->compose(['mode' => 'edit', 'componentKey' => 'PresentationGroup', 'composition' => $this->preset('group-type-t'), 'prompt' => 'Demande 6']);

        $this->assertSame(429, $response->getStatusCode(), $response->getContent());
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertCount(5, FakeLandingAiClient::$requests);
    }

    public function testNonAdminIsRefused(): void
    {
        $this->session = $this->login(['ROLE_USER_INTERNET']);

        $response = $this->compose(['mode' => 'edit', 'componentKey' => 'PresentationGroup', 'composition' => $this->preset('group-type-t'), 'prompt' => 'Titre en noir.']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(403, $this->get('/api/landingpage-ai/usage')->getStatusCode());
        $this->assertSame([], FakeLandingAiClient::$requests);
    }

    public function testInvalidRequestsGive400(): void
    {
        $cases = [
            'mode inconnu' => ['mode' => 'magie', 'componentKey' => 'PresentationGroup', 'composition' => new \stdClass(), 'prompt' => 'x'],
            'famille inconnue' => ['mode' => 'edit', 'componentKey' => 'Inconnue', 'composition' => new \stdClass(), 'prompt' => 'x'],
            'demande vide' => ['mode' => 'edit', 'componentKey' => 'PresentationGroup', 'composition' => new \stdClass(), 'prompt' => ''],
            'demande trop longue' => ['mode' => 'edit', 'componentKey' => 'PresentationGroup', 'composition' => new \stdClass(), 'prompt' => str_repeat('a', 2001)],
            'composition absente' => ['mode' => 'edit', 'componentKey' => 'PresentationGroup', 'prompt' => 'x'],
            'média invalide' => ['mode' => 'edit', 'componentKey' => 'PresentationGroup', 'composition' => new \stdClass(), 'prompt' => 'x', 'media' => [['kind' => 'image', 'url' => 'javascript:alert(1)']]],
            'page : famille imposée inconnue' => ['mode' => 'page', 'componentKey' => 'Inconnue', 'prompt' => 'x'],
            'image : pas une data URL' => ['mode' => 'page', 'prompt' => 'x', 'images' => ['https://example.com/capture.png']],
            'image : type annoncé différent du contenu' => ['mode' => 'page', 'prompt' => 'x', 'images' => [str_replace('image/png', 'image/jpeg', $this->pngDataUrl())]],
            'image : contenu illisible' => ['mode' => 'page', 'prompt' => 'x', 'images' => ['data:image/png;base64,AAAA']],
            'image : plus de 5' => ['mode' => 'page', 'prompt' => 'x', 'images' => array_fill(0, 6, $this->pngDataUrl())],
            'images : pas une liste' => ['mode' => 'page', 'prompt' => 'x', 'images' => 'data:image/png;base64,AAAA'],
        ];
        foreach ($cases as $name => $body) {
            $response = $this->compose($body);
            $this->assertSame(400, $response->getStatusCode(), "$name : " . $response->getContent());
            $this->assertArrayHasKey('message', json_decode($response->getContent(), true));
        }
        $this->assertSame([], FakeLandingAiClient::$requests);
        $this->assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM ai_usage'));
    }

    public function testConcurrentRequestsAtTheQuotaEdgeOnlyOneIsAccepted(): void
    {
        $this->db()->executeStatement('INSERT INTO ai_credit_setting (monthly_credits) VALUES (1)');

        // Le test tient le verrou du quota : les deux processus attendent, puis passent l'un après l'autre
        $lock = $this->db();
        $lock->executeQuery('SELECT pg_advisory_lock(?)', [\App\Services\LandingAiService\LandingAiQuotaService::LOCK_KEY]);
        $worker = dirname(__DIR__, 2) . '/Fixtures/landingpage/reserve_worker.php';
        $processes = [];
        foreach ([1, 2] as $i) {
            $processes[$i] = new Process(['php', $worker, MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, '1']);
            $processes[$i]->start();
        }
        usleep(1500000);
        $lock->executeQuery('SELECT pg_advisory_unlock(?)', [\App\Services\LandingAiService\LandingAiQuotaService::LOCK_KEY]);

        $outputs = [];
        foreach ($processes as $process) {
            $process->wait();
            $outputs[] = trim($process->getOutput()) ?: trim($process->getErrorOutput());
        }
        sort($outputs);
        $this->assertSame(['quota', 'reserved'], $outputs);
        $this->assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM ai_usage WHERE status = 'reserved'"));
    }

    public function testUsageEndpointReturnsCreditsAndHistory(): void
    {
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::editResponse([]);
        $this->compose(['mode' => 'edit', 'componentKey' => 'PresentationGroup', 'composition' => $this->preset('group-type-t'), 'prompt' => 'Une demande.']);

        $response = $this->get('/api/landingpage-ai/usage');

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertSame(['monthly', 'used', 'remaining', 'resetAt'], array_keys($body['credits']));
        $this->assertStringStartsWith((new \DateTimeImmutable('first day of next month', new \DateTimeZone('America/Toronto')))->format('Y-m-01'), $body['credits']['resetAt']);
        $this->assertSame(['id', 'createdAt', 'user', 'mode', 'componentKey', 'status', 'credits', 'model', 'attempts', 'durationMs', 'promptExcerpt'], array_keys($body['history'][0]));
    }

    public function testCreateReturnsCompositionAndChosenDataType(): void
    {
        $groups = $this->availableIds('PresentationGroup');
        $this->assertNotEmpty($groups, 'la base de test contient des groupes');
        $composition = $this->preset('group-type-a');
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::createResponse($composition, (int) end($groups), 'Grille des forfaits.');

        $response = $this->compose(['mode' => 'create', 'componentKey' => 'PresentationGroup', 'dataType' => $groups[0], 'prompt' => 'Section qui présente mes forfaits.']);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), false);
        $this->assertSame('create', $body->mode);
        $this->assertSame((string) end($groups), $body->dataType, 'donnée choisie par l\'IA, renvoyée en texte');
        $this->assertSame(json_encode($composition), json_encode($body->composition));
        $this->assertSame(3, $body->credits->used, 'création : 3 crédits');

        $request = FakeLandingAiClient::$requests[0];
        $this->assertSame('creer_composition', $request['tools'][0]['name']);
        $this->assertSame(['type' => 'tool', 'name' => 'creer_composition'], $request['tool_choice']);
        $this->assertStringContainsString('valeurs possibles de dataType', $request['messages'][0]['content']);
        $this->assertStringContainsString('Donnée sélectionnée par défaut dans l\'éditeur : ' . $groups[0], $request['messages'][0]['content']);
        $history = json_decode($this->get('/api/landingpage-ai/usage')->getContent(), true)['history'];
        $this->assertSame(['create', 'success', 3], [$history[0]['mode'], $history[0]['status'], $history[0]['credits']]);
    }

    public function testCreateWithDataTypeOutsideTheSiteDataTriggersAnotherAttempt(): void
    {
        $groups = $this->availableIds('PresentationGroup');
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::createResponse($this->preset('group-type-a'), '999999');
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::createResponse($this->preset('group-type-a'), $groups[0]);

        $response = $this->compose(['mode' => 'create', 'componentKey' => 'PresentationGroup', 'prompt' => 'Une grille.']);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $feedback = FakeLandingAiClient::$requests[1]['messages'][2]['content'][0]['content'];
        $this->assertStringContainsString('dataType', $feedback);
        $this->assertStringContainsString('creer_composition', $feedback);
    }

    public function testCreateForAFamilyWithoutDataRequiresNullDataType(): void
    {
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::createResponse($this->preset('contact-type-a'), '1');
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::createResponse($this->preset('contact-type-a'), null);

        $response = $this->compose(['mode' => 'create', 'componentKey' => 'Contact', 'prompt' => 'Formulaire de contact.']);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertNull(json_decode($response->getContent())->dataType);
        $this->assertStringContainsString('null attendu', FakeLandingAiClient::$requests[1]['messages'][2]['content'][0]['content']);
        $this->assertStringContainsString('n\'utilise pas de donnée', FakeLandingAiClient::$requests[0]['messages'][0]['content']);
    }

    public function testDataFamiliesFollowComponentsConfig(): void
    {
        $this->db();
        $data = static::getContainer()->get(\App\Services\LandingAiService\LandingAiDataSources::class);

        foreach (['Presentation', 'PresentationGroup', 'BaniereStatique', 'Video', 'Embed', 'MultiLien', 'Candidature', 'Marque', 'Reservation'] as $family) {
            $this->assertTrue($data->familyUsesData($family), $family);
        }
        foreach (['Recherche', 'Contact', 'APropos', 'Baniere', 'Service', 'Carousel', 'Footer', 'Navbar'] as $family) {
            $this->assertFalse($data->familyUsesData($family), $family);
            $this->assertSame([], $data->available($family), $family);
        }
        $this->assertTrue($data->dataOptional('Reservation'));
        $this->assertFalse($data->dataOptional('Presentation'));
    }

    public function testCreateReservationAcceptsNullForAllServicesOrAChosenService(): void
    {
        $services = $this->availableIds('Reservation');
        $this->assertNotEmpty($services, 'prestations du site (ou liste par défaut)');

        FakeLandingAiClient::$queue[] = FakeLandingAiClient::createResponse($this->preset('reservation-type-b'), null);
        $response = $this->compose(['mode' => 'create', 'componentKey' => 'Reservation', 'prompt' => 'Formulaire de réservation.']);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertNull(json_decode($response->getContent())->dataType, 'null = toutes les prestations');
        $this->assertStringContainsString('Donnée facultative', FakeLandingAiClient::$requests[0]['messages'][0]['content']);

        FakeLandingAiClient::$queue[] = FakeLandingAiClient::createResponse($this->preset('reservation-type-b'), $services[0]);
        $response = $this->compose(['mode' => 'create', 'componentKey' => 'Reservation', 'dataType' => $services[0], 'prompt' => 'Réservation de cette prestation.']);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertSame($services[0], json_decode($response->getContent())->dataType);
        $this->assertCount(2, FakeLandingAiClient::$requests, 'acceptées au premier essai');
    }

    public function testCreateRechercheRequiresNullDataType(): void
    {
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::createResponse($this->preset('recherche-bandeau'), '1');
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::createResponse($this->preset('recherche-bandeau'), null);

        $response = $this->compose(['mode' => 'create', 'componentKey' => 'Recherche', 'prompt' => 'Bandeau de recherche.']);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertNull(json_decode($response->getContent())->dataType);
        $this->assertStringContainsString('null attendu', FakeLandingAiClient::$requests[1]['messages'][2]['content'][0]['content']);
    }

    public function testCreateRejectsAnUnknownDefaultDataType(): void
    {
        $response = $this->compose(['mode' => 'create', 'componentKey' => 'PresentationGroup', 'dataType' => '999999', 'prompt' => 'Une grille.']);
        $this->assertSame(400, $response->getStatusCode(), $response->getContent());

        $response = $this->compose(['mode' => 'create', 'componentKey' => 'Contact', 'dataType' => '1', 'prompt' => 'Contact.']);
        $this->assertSame(400, $response->getStatusCode(), $response->getContent());
        $this->assertSame([], FakeLandingAiClient::$requests);
    }

    public function testCreateAcceptsUrlsWrittenInThePromptAndPresetImages(): void
    {
        $composition = $this->preset('presentation-type-b'); // image de fond du modèle : /landingpage/presets/type-b-fond.webp
        $composition->blocks[] = (object) ['id' => 'c-video', 'type' => 'video', 'parentId' => null, 'x' => 54, 'y' => 80, 'w' => 36, 'h' => 15, 'url' => 'https://media.example.com/intro.mp4'];
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::createResponse($composition, $this->availableIds('Presentation')[0]);

        $response = $this->compose(['mode' => 'create', 'componentKey' => 'Presentation', 'prompt' => 'Héros avec cette vidéo : https://media.example.com/intro.mp4, titre et bouton de contact.']);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertCount(1, FakeLandingAiClient::$requests, 'accepté au premier essai');
    }

    public function testCreateWithAnInventedImageTriggersAnotherAttempt(): void
    {
        $invented = $this->preset('presentation-type-b');
        $invented->bgImage = 'https://images.example.com/inventee.jpg';
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::createResponse($invented, $this->availableIds('Presentation')[0]);
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::createResponse($this->preset('presentation-type-b'), $this->availableIds('Presentation')[0]);

        $response = $this->compose(['mode' => 'create', 'componentKey' => 'Presentation', 'prompt' => 'Une présentation.']);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertStringContainsString('bgImage', FakeLandingAiClient::$requests[1]['messages'][2]['content'][0]['content']);
    }

    public function testPageReturnsSectionsOnThePageModelWithImagesFor10Credits(): void
    {
        $presentations = $this->availableIds('Presentation');
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::pageResponse([
            ['componentKey' => 'Presentation', 'dataType' => (int) $presentations[0], 'composition' => $this->preset('presentation-type-b')],
            ['componentKey' => 'Contact', 'dataType' => null, 'composition' => $this->preset('contact-type-a')],
        ], 'Héros puis contact.');

        $body = $this->composeInBackground(['mode' => 'page', 'prompt' => 'Page d\'accueil : héros puis contact, avec ma charte.', 'images' => [$this->pngDataUrl()]])->result;

        $this->assertSame('page', $body->mode);
        $this->assertObjectNotHasProperty('composition', $body);
        $this->assertSame(['Presentation', 'Contact'], array_column((array) $body->sections, 'componentKey'));
        $this->assertSame([(string) $presentations[0], null], array_map(fn ($s) => $s->dataType, $body->sections));
        $this->assertSame(json_encode($this->preset('contact-type-a')), json_encode($body->sections[1]->composition));
        $this->assertSame(10, $body->credits->used, 'page : 10 crédits');

        $request = FakeLandingAiClient::$requests[0];
        $pageModel = static::getContainer()->get(\App\Services\LandingAiService\LandingAiComposer::class)->pageModel();
        $this->assertSame($pageModel, $request['model']);
        $this->assertSame($pageModel, $body->usage->model);
        $this->assertSame('composer_page', $request['tools'][0]['name']);
        $this->assertSame(['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => substr($this->pngDataUrl(), 22)]], $request['messages'][0]['content'][0]);
        $this->assertStringContainsString('Données du site par famille', $request['messages'][0]['content'][1]['text']);
        $this->assertStringContainsString('CATALOGUE DE L\'ÉDITEUR', $request['system'][2]['text']);
        $this->assertEqualsWithDelta(\App\Services\LandingAiService\LandingAiComposer::PAGE_CALL_TIMEOUT, FakeLandingAiClient::$timeouts[0], 1.0, 'page : délai long');
        $history = json_decode($this->get('/api/landingpage-ai/usage')->getContent(), true)['history'];
        $this->assertSame(['page', '', 'success', 10], [$history[0]['mode'], $history[0]['componentKey'], $history[0]['status'], $history[0]['credits']]);
    }

    public function testPageWithAnInvalidSectionIsRetriedWithTheSectionPath(): void
    {
        $contact = $this->preset('contact-type-a');
        $invented = $this->preset('presentation-type-b');
        $invented->bgImage = 'https://images.example.com/inventee.jpg';
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::pageResponse([
            ['componentKey' => 'Presentation', 'dataType' => $this->availableIds('Presentation')[0], 'composition' => $invented],
            ['componentKey' => 'Contact', 'dataType' => '1', 'composition' => $contact],
        ]);
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::pageResponse([
            ['componentKey' => 'Presentation', 'dataType' => $this->availableIds('Presentation')[0], 'composition' => $this->preset('presentation-type-b')],
            ['componentKey' => 'Contact', 'dataType' => null, 'composition' => $contact],
        ]);

        $job = $this->composeInBackground(['mode' => 'page', 'prompt' => 'Héros et contact.']);

        $this->assertSame('done', $job->status);
        $feedback = FakeLandingAiClient::$requests[1]['messages'][2]['content'][0]['content'];
        $this->assertStringContainsString('sections[0].composition.bgImage', $feedback);
        $this->assertStringContainsString('sections[1].dataType', $feedback);
        $this->assertStringContainsString('TOUTES les sections', $feedback);
        $this->assertSame(10, $job->result->credits->used, 'sans images, une page coûte aussi 10 crédits');
    }

    public function testPageWithAnImposedFamilyRejectsOtherFamilies(): void
    {
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::pageResponse([['componentKey' => 'Contact', 'dataType' => null, 'composition' => $this->preset('contact-type-a')]]);
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::pageResponse([['componentKey' => 'Presentation', 'dataType' => $this->availableIds('Presentation')[0], 'composition' => $this->preset('presentation-type-b')]]);

        $this->assertSame('done', $this->composeInBackground(['mode' => 'page', 'componentKey' => 'Presentation', 'prompt' => 'Reproduis cette section.', 'images' => [$this->pngDataUrl()]])->status);
        $this->assertStringContainsString('famille imposée : Presentation', FakeLandingAiClient::$requests[1]['messages'][2]['content'][0]['content']);
        $this->assertStringContainsString('Famille imposée pour toutes les sections : Presentation', FakeLandingAiClient::$requests[0]['messages'][0]['content'][1]['text']);
        $history = json_decode($this->get('/api/landingpage-ai/usage')->getContent(), true)['history'];
        $this->assertSame('Presentation', $history[0]['componentKey']);
    }

    public function testEditWithAnImageUsesTheImagesModelAndCosts10(): void
    {
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::editResponse([['op' => 'update', 'id' => 't-titre', 'set' => ['color' => '#000000']]]);

        $job = $this->composeInBackground(['mode' => 'edit', 'componentKey' => 'PresentationGroup', 'composition' => $this->preset('group-type-t'), 'prompt' => 'Titre de la couleur de la capture.', 'images' => [$this->pngDataUrl()]]);

        $this->assertSame('edit', $job->result->mode, 'une retouche avec image passe aussi en tâche de fond');
        $request = FakeLandingAiClient::$requests[0];
        $composer = static::getContainer()->get(\App\Services\LandingAiService\LandingAiComposer::class);
        $this->assertSame($composer->imagesModel(), $request['model']);
        $this->assertSame($composer->pageModel(), $composer->imagesModel(), 'sans LANDING_AI_MODEL_IMAGES : modèle du mode page');
        $this->assertSame('image', $request['messages'][0]['content'][0]['type']);
        $this->assertSame(10, $job->result->credits->used);
    }

    public function testImagesModelSettingAppliesToImageRequestsButNotToPageMode(): void
    {
        $before = $_ENV['LANDING_AI_MODEL_IMAGES'] ?? '';
        $_ENV['LANDING_AI_MODEL_IMAGES'] = $_SERVER['LANDING_AI_MODEL_IMAGES'] = 'modele-des-images';
        try {
            FakeLandingAiClient::$queue[] = FakeLandingAiClient::editResponse([['op' => 'update', 'id' => 't-titre', 'set' => ['color' => '#000000']]]);
            $this->composeInBackground(['mode' => 'edit', 'componentKey' => 'PresentationGroup', 'composition' => $this->preset('group-type-t'), 'prompt' => 'Titre de la couleur de la capture.', 'images' => [$this->pngDataUrl()]]);
            $this->assertSame('modele-des-images', FakeLandingAiClient::$requests[0]['model']);

            FakeLandingAiClient::$requests = [];
            FakeLandingAiClient::$queue[] = FakeLandingAiClient::pageResponse([['componentKey' => 'Contact', 'dataType' => null, 'composition' => $this->preset('contact-type-a')]]);
            $this->composeInBackground(['mode' => 'page', 'prompt' => 'Section contact, avec ma charte.', 'images' => [$this->pngDataUrl()]]);
            $composer = static::getContainer()->get(\App\Services\LandingAiService\LandingAiComposer::class);
            $this->assertSame($composer->pageModel(), FakeLandingAiClient::$requests[0]['model'], 'le mode page garde son modèle, même avec des images');
        } finally {
            $_ENV['LANDING_AI_MODEL_IMAGES'] = $_SERVER['LANDING_AI_MODEL_IMAGES'] = $before;
        }
    }

    public function testEditRefusesAnInvalidCompositionBeforeAnyAiCall(): void
    {
        $composition = $this->preset('group-type-t');
        $composition->blocks[0]->x = 150; // x + w > 100 : composition déjà invalide

        $response = $this->compose(['mode' => 'edit', 'componentKey' => 'PresentationGroup', 'composition' => $composition, 'prompt' => 'Titre en noir.']);

        $this->assertSame(422, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent());
        $this->assertSame('Composition invalide', $body->error);
        $this->assertStringContainsString('blocks[0]', $body->errors[0]->path);
        $this->assertSame([], FakeLandingAiClient::$requests, 'aucun appel à l\'IA');
        $this->assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM ai_usage'), 'aucune réservation');
        $limiter = static::getContainer()->get('limiter.landing_ai_tenant')->create('tenant:' . MV_TEST_TENANT_CODE);
        $this->assertSame(5, $limiter->consume(0)->getRemainingTokens(), 'limite par minute non consommée');
    }

    public function testEditRefusesAnOversizedComposition(): void
    {
        $composition = $this->preset('group-type-t');
        $composition->translations = (object) ['en' => (object) ['bourrage' => str_repeat('x', 210000)]];

        $response = $this->compose(['mode' => 'edit', 'componentKey' => 'PresentationGroup', 'composition' => $composition, 'prompt' => 'Titre en noir.']);

        $this->assertSame(400, $response->getStatusCode(), $response->getContent());
        $this->assertStringContainsString('200 Ko', $response->getContent());
        $this->assertSame([], FakeLandingAiClient::$requests);
    }

    public function testOversizedBodyGets413InJson(): void
    {
        $response = $this->request('POST', '/api/landingpage-ai/compose', str_repeat(' ', \App\Dto\LandingAiComposeInputDto::maxBodyBytes() + 1));

        $this->assertSame(413, $response->getStatusCode());
        $this->assertSame('Requête trop volumineuse', json_decode($response->getContent())->error);
    }

    public function testImageOverTheApiLimitIsRefused(): void
    {
        $response = $this->compose(['mode' => 'page', 'prompt' => 'x', 'images' => ['data:image/png;base64,' . str_repeat('A', \App\Dto\LandingAiComposeInputDto::MAX_IMAGE_BASE64_BYTES + 4)]]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('trop lourde', $response->getContent());
    }

    public function testProposalWithALinkInATextIsSentBackForCorrection(): void
    {
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::editResponse([['op' => 'update', 'id' => 't-titre', 'set' => ['text' => 'Voir <a href="https://exemple.com">ici</a>']]]);
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::editResponse([['op' => 'update', 'id' => 't-titre', 'set' => ['text' => 'Voir <strong>ici</strong>']]]);

        $response = $this->compose(['mode' => 'edit', 'componentKey' => 'PresentationGroup', 'composition' => $this->preset('group-type-t'), 'prompt' => 'Ajoute un lien.']);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertStringContainsString('balise <a> non autorisée', FakeLandingAiClient::$requests[1]['messages'][2]['content'][0]['content']);
        $this->assertStringContainsString('jamais de lien <a>', FakeLandingAiClient::$requests[0]['system'][0]['text']);
    }

    public function testSecondBackgroundRequestWhileOneIsPendingGets409WithTheExistingJob(): void
    {
        $body = ['mode' => 'page', 'prompt' => 'Une page.'];
        $first = json_decode($this->compose($body)->getContent());
        $messages = $this->sentJobMessages();

        $response = $this->compose($body);

        $this->assertSame(409, $response->getStatusCode(), $response->getContent());
        $conflict = json_decode($response->getContent());
        $this->assertSame([$first->jobId, 'pending'], [$conflict->jobId, $conflict->status]);
        $this->assertSame('/api/landingpage-ai/jobs/' . $first->jobId, $response->headers->get('Location'));
        $this->assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM ai_usage'), 'une seule réservation');

        FakeLandingAiClient::$queue[] = FakeLandingAiClient::pageResponse([['componentKey' => 'Contact', 'dataType' => null, 'composition' => $this->preset('contact-type-a')]]);
        $this->runWorker($messages);
        $this->assertSame(202, $this->compose($body)->getStatusCode(), 'tâche terminée : nouvelle demande acceptée');
    }

    public function testTransientApiErrorsAreRetried(): void
    {
        FakeLandingAiClient::$queue = [
            new \App\Services\AnthropicApiException(529, 'overloaded_error', 0),
            new \App\Services\AnthropicApiException(429, 'rate_limit_error', 0),
            FakeLandingAiClient::editResponse([['op' => 'update', 'id' => 't-titre', 'set' => ['color' => '#000000']]]),
        ];

        $response = $this->compose(['mode' => 'edit', 'componentKey' => 'PresentationGroup', 'composition' => $this->preset('group-type-t'), 'prompt' => 'Titre en noir.']);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertCount(3, FakeLandingAiClient::$requests);
        $this->assertSame(1, json_decode($response->getContent())->usage->attempts, 'les reprises ne sont pas des essais de proposition');
    }

    public function testPersistentOverloadGives502AfterRetriesAndANonTransientErrorIsNotRetried(): void
    {
        $overloaded = fn () => new \App\Services\AnthropicApiException(529, 'overloaded_error', 0);
        FakeLandingAiClient::$queue = [$overloaded(), $overloaded(), $overloaded()];
        $body = ['mode' => 'edit', 'componentKey' => 'PresentationGroup', 'composition' => $this->preset('group-type-t'), 'prompt' => 'Titre en noir.'];

        $this->assertSame(502, $this->compose($body)->getStatusCode());
        $this->assertCount(1 + \App\Services\LandingAiService\LandingAiComposer::TRANSIENT_RETRIES, FakeLandingAiClient::$requests);

        FakeLandingAiClient::reset();
        FakeLandingAiClient::$queue = [new \App\Services\AnthropicApiException(400, 'invalid_request_error')];
        $this->assertSame(502, $this->compose($body)->getStatusCode());
        $this->assertCount(1, FakeLandingAiClient::$requests, 'erreur de requête : pas de nouvel essai');
    }

    public function testTuningIsOffByDefaultAndSetsEffortAndCacheTtlWhenConfigured(): void
    {
        $container = static::getContainer();
        $args = ['PresentationGroup', $this->preset('group-type-t'), 'Titre en noir.', 'fr', [], [], ['colors' => [], 'fonts' => []]];
        $image = [['mediaType' => 'image/png', 'data' => substr($this->pngDataUrl(), 22)]];

        $default = $container->get(\App\Services\LandingAiService\LandingAiPromptBuilder::class)->editPayload('claude-sonnet-5', ...$args);
        $this->assertArrayNotHasKey('output_config', $default, 'sans réglage : effort par défaut du modèle');
        $this->assertSame(['type' => 'ephemeral'], $default['system'][1]['cache_control'], 'sans réglage : cache de 5 minutes');
        $this->assertStringNotContainsString('<exemples_de_la_famille>', $default['messages'][0]['content'], 'retouche : aucun exemple par défaut');

        $withExamples = new \App\Services\LandingAiService\LandingAiPromptBuilder(
            $container->get(LandingAiCatalogue::class),
            $container->get(\App\Services\LandingConfigService\LandingConfigStore::class),
            new \App\Services\LandingAiService\LandingAiTuning(null, null, null, null, null, '1')
        );
        $content = $withExamples->editPayload('claude-sonnet-5', ...$args)['messages'][0]['content'];
        $this->assertStringContainsString('<exemples_de_la_famille>', $content);
        $this->assertStringNotContainsString('"id":"group-type-t"', $content, 'jamais le modèle d\'origine de la section (doublon)');

        $tuned = new \App\Services\LandingAiService\LandingAiPromptBuilder(
            $container->get(LandingAiCatalogue::class),
            $container->get(\App\Services\LandingConfigService\LandingConfigStore::class),
            new \App\Services\LandingAiService\LandingAiTuning('low', 'medium', 'high', 'medium', '1h')
        );
        $edit = $tuned->editPayload('claude-sonnet-5', ...$args);
        $this->assertSame(['effort' => 'low'], $edit['output_config']);
        $this->assertSame(['type' => 'ephemeral'], $edit['system'][1]['cache_control'], 'cache d\'une heure réservé au modèle page');

        $review = $tuned->editPayload('claude-opus-5-5', ...[...$args, $image]);
        $this->assertSame(['effort' => 'medium'], $review['output_config'], 'retouche avec images : réglage « images »');
        $this->assertSame(['type' => 'ephemeral', 'ttl' => '1h'], $review['system'][1]['cache_control']);
        $this->assertSame(['type' => 'ephemeral', 'ttl' => '1h'], $review['system'][2]['cache_control']);

        $ignored = new \App\Services\LandingAiService\LandingAiTuning('turbo');
        $this->assertNull($ignored->effort('edit'), 'valeur inconnue ignorée');
    }

    public function testStutteredNumericKeyIsRepairedWithoutAnotherAttempt(): void
    {
        $composition = $this->preset('contact-type-a');
        $composition->blocks[0]->dividerWidth100 = 100;   // double de "dividerWidth": 100
        unset($composition->blocks[1]->dividerWidth);
        $composition->blocks[1]->dividerWidth50 = 50;     // la propriété manquait : rétablie
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::createResponse($composition, null);

        $response = $this->compose(['mode' => 'create', 'componentKey' => 'Contact', 'prompt' => 'Formulaire de contact.']);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertCount(1, FakeLandingAiClient::$requests, 'aucun nouvel essai');
        $blocks = json_decode($response->getContent())->composition->blocks;
        $this->assertObjectNotHasProperty('dividerWidth100', $blocks[0]);
        $this->assertObjectNotHasProperty('dividerWidth50', $blocks[1]);
        $this->assertSame(50, $blocks[1]->dividerWidth);
    }

    public function testOtherUnknownKeysAreStillSentBackToTheModel(): void
    {
        $wrong = $this->preset('contact-type-a');
        $wrong->blocks[0]->dividerWidth100 = 40; // valeur différente du nombre collé : intention ambiguë, pas de réparation
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::createResponse($wrong, null);
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::createResponse($this->preset('contact-type-a'), null);

        $response = $this->compose(['mode' => 'create', 'componentKey' => 'Contact', 'prompt' => 'Formulaire de contact.']);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertStringContainsString('dividerWidth100', FakeLandingAiClient::$requests[1]['messages'][2]['content'][0]['content']);
    }

    public function testContainerWithoutBackgroundBecomesTransparentInACreation(): void
    {
        $composition = $this->preset('contact-type-a');
        $index = array_key_first(array_filter($composition->blocks, fn ($b) => $b->type === 'container'));
        unset($composition->blocks[$index]->background); // rendu : blanc opaque
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::createResponse($composition, null);

        $response = $this->compose(['mode' => 'create', 'componentKey' => 'Contact', 'prompt' => 'Formulaire de contact.']);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertCount(1, FakeLandingAiClient::$requests, 'aucun nouvel essai');
        $this->assertSame('transparent', json_decode($response->getContent())->composition->blocks[$index]->background);
    }

    public function testEditOnlyCompletesTheBackgroundOfAddedContainers(): void
    {
        $composition = $this->preset('group-type-t');
        $index = array_key_first(array_filter($composition->blocks, fn ($b) => $b->type === 'container'));
        unset($composition->blocks[$index]->background);
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::editResponse([['op' => 'add', 'block' => ['id' => 't-cadre', 'type' => 'container', 'parentId' => null]]]);

        $response = $this->compose(['mode' => 'edit', 'componentKey' => 'PresentationGroup', 'composition' => $composition, 'prompt' => 'Ajoute un cadre vide en bas.']);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $blocks = array_column(json_decode($response->getContent())->composition->blocks, null, 'id');
        $this->assertSame('transparent', $blocks['t-cadre']->background, 'container ajouté par l\'IA');
        $this->assertObjectNotHasProperty('background', $blocks[$composition->blocks[$index]->id], 'bloc de départ non visé : inchangé');
    }

    public function testButtonWithoutBackgroundIsSentBackToTheModel(): void
    {
        $wrong = $this->preset('presentation-type-b');
        $index = array_key_first(array_filter($wrong->blocks, fn ($b) => $b->type === 'button'));
        unset($wrong->blocks[$index]->background); // rendu : blanc opaque, couleur impossible à deviner
        $dataType = $this->availableIds('Presentation')[0];
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::createResponse($wrong, $dataType);
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::createResponse($this->preset('presentation-type-b'), $dataType);

        $response = $this->compose(['mode' => 'create', 'componentKey' => 'Presentation', 'prompt' => 'Section de présentation.']);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $retry = FakeLandingAiClient::$requests[1]['messages'][2]['content'][0]['content'];
        $this->assertStringContainsString("blocks[$index].background", $retry);
        $this->assertStringContainsString('fond blanc opaque', $retry);
    }

    public function testEditKeepsAnExistingButtonWithoutBackground(): void
    {
        $composition = $this->preset('presentation-type-b');
        $index = array_key_first(array_filter($composition->blocks, fn ($b) => $b->type === 'button'));
        unset($composition->blocks[$index]->background);
        $title = array_values(array_filter($composition->blocks, fn ($b) => $b->type === 'title'))[0];
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::editResponse([['op' => 'update', 'id' => $title->id, 'set' => ['color' => '#000000']]]);

        $response = $this->compose(['mode' => 'edit', 'componentKey' => 'Presentation', 'composition' => $composition, 'prompt' => 'Titre en noir.']);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertCount(1, FakeLandingAiClient::$requests, 'un bloc de départ sans fond n\'est pas une erreur de l\'IA');
    }

    public function testRefusedNullAndUnusedTextStyleAreDroppedWithoutAnotherAttempt(): void
    {
        $composition = $this->preset('contact-type-a');
        $composition->blocks[1]->fontFamily = null;               // null au premier niveau : traité comme absent
        $composition->blocks[3]->fontFamily = 'Montserrat';       // style de texte sans effet sur un formulaire
        $composition->blocks[0]->fontFamily = 'Montserrat';       // container : permis par le contrat, transmis aux enfants
        $composition->blocks[0]->mobile = (object) ['minHeight' => null]; // dans mobile, null a un sens : conservé
        $composition->bgImage = null;
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::createResponse($composition, null);

        $response = $this->compose(['mode' => 'create', 'componentKey' => 'Contact', 'prompt' => 'Formulaire de contact.']);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertCount(1, FakeLandingAiClient::$requests, 'aucun nouvel essai');
        $result = json_decode($response->getContent())->composition;
        $this->assertObjectNotHasProperty('fontFamily', $result->blocks[1]);
        $this->assertObjectNotHasProperty('fontFamily', $result->blocks[3]);
        $this->assertSame('Montserrat', $result->blocks[0]->fontFamily, 'la police d\'un container n\'est jamais retirée');
        $this->assertObjectNotHasProperty('bgImage', $result);
        $this->assertTrue(property_exists($result->blocks[0]->mobile, 'minHeight') && $result->blocks[0]->mobile->minHeight === null, 'mobile.minHeight = null conservé');
    }

    public function testOtherPropertiesRefusedForABlockTypeAreStillSentBackToTheModel(): void
    {
        $wrong = $this->preset('contact-type-a');
        $wrong->blocks[1]->valign = 'center'; // réservé aux containers : l'intention du modèle n'est pas un simple style de texte
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::createResponse($wrong, null);
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::createResponse($this->preset('contact-type-a'), null);

        $response = $this->compose(['mode' => 'create', 'componentKey' => 'Contact', 'prompt' => 'Formulaire de contact.']);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertStringContainsString('blocks[1].valign', FakeLandingAiClient::$requests[1]['messages'][2]['content'][0]['content']);
    }

    public function testInventedFallbackUrlOnABoundImageIsDroppedWithoutAnotherAttempt(): void
    {
        $composition = $this->preset('contact-type-a');
        $composition->blocks[] = (object) ['id' => 'a-logo', 'type' => 'image', 'parentId' => null, 'url' => 'https://images.example.com/logo.png', 'bindings' => (object) ['url' => 'logoUrl']];
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::createResponse($composition, null);

        $response = $this->compose(['mode' => 'create', 'componentKey' => 'Contact', 'prompt' => 'Formulaire de contact avec le logo.']);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertCount(1, FakeLandingAiClient::$requests, 'aucun nouvel essai');
        $logo = array_column(json_decode($response->getContent())->composition->blocks, null, 'id')['a-logo'];
        $this->assertObjectNotHasProperty('url', $logo);
        $this->assertSame('logoUrl', $logo->bindings->url);
    }

    public function testInventedUrlOnAnUnboundImageIsStillSentBackToTheModel(): void
    {
        $wrong = $this->preset('contact-type-a');
        $wrong->blocks[] = (object) ['id' => 'a-photo', 'type' => 'image', 'parentId' => null, 'url' => 'https://images.example.com/photo.jpg'];
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::createResponse($wrong, null);
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::createResponse($this->preset('contact-type-a'), null);

        $response = $this->compose(['mode' => 'create', 'componentKey' => 'Contact', 'prompt' => 'Formulaire de contact avec une photo.']);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertStringContainsString('média absent de la liste autorisée', FakeLandingAiClient::$requests[1]['messages'][2]['content'][0]['content']);
    }

    public function testVideoPromptIsWrittenForOneCredit(): void
    {
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::videoPromptResponse([
            'prompt' => 'A single continuous shot of a laptop assembling itself on a plain #0e1533 background.',
            'promptMobile' => 'Vertical 9:16 version of the same scene.',
            'steps' => [
                ['at' => 100, 'title' => 'Accompagner', 'text' => 'Nous restons à vos côtés.'],
                ['at' => 0, 'title' => '<b>Écouter</b>', 'text' => 'Nous comprenons votre projet.'],
                ['at' => 40, 'title' => 'Concevoir', 'text' => 'Nous dessinons la solution.'],
            ],
            'notes' => ['Vérifiez que le fond reste uni.'],
        ]);

        $response = $this->videoPrompt(['prompt' => 'Un ordinateur portable qui s\'assemble pièce par pièce.', 'format' => 'both', 'duration' => 8, 'textSide' => 'left', 'background' => '#0E1533', 'locale' => 'fr']);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent());
        $this->assertStringContainsString('single continuous shot', $body->prompt);
        $this->assertSame('Vertical 9:16 version of the same scene.', $body->promptMobile);
        $this->assertSame([0, 40, 85], array_column($body->steps, 'at'), 'étapes triées, la dernière ramenée à 85');
        $this->assertSame('Écouter', $body->steps[0]->title, 'texte brut, sans balises');
        $this->assertSame(['Vérifiez que le fond reste uni.'], $body->notes);
        $this->assertSame(1, $body->credits->used, 'un crédit');

        $request = FakeLandingAiClient::$requests[0];
        $this->assertSame(static::getContainer()->get(\App\Services\LandingAiService\LandingAiComposer::class)->editModel(), $request['model'], 'modèle de la retouche');
        $this->assertSame(['type' => 'tool', 'name' => 'ecrire_prompt_video'], $request['tool_choice']);
        $user = $request['messages'][0]['content'];
        foreach (['Format : both', 'Durée : 8 secondes', 'Côté du texte : left', 'Couleur du fond : #0e1533', 'Un ordinateur portable'] as $expected) {
            $this->assertStringContainsString($expected, $user);
        }
        $history = json_decode($this->get('/api/landingpage-ai/usage')->getContent(), true)['history'];
        $this->assertSame(['video', 'Video', 'success', 1], [$history[0]['mode'], $history[0]['componentKey'], $history[0]['status'], $history[0]['credits']]);
    }

    public function testVideoPromptWithoutMobileVersionWhenTheFormatIsNotBoth(): void
    {
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::videoPromptResponse([
            'prompt' => 'A slow rotation of a ceramic vase on a plain #ffffff background.', 'promptMobile' => 'ignored',
            'steps' => [['at' => 0, 'title' => 'La matière', 'text' => ''], ['at' => 40, 'title' => 'La forme', 'text' => ''], ['at' => 80, 'title' => 'La finition', 'text' => '']],
            'notes' => [],
        ]);

        $response = $this->videoPrompt(['prompt' => 'Un vase qui tourne lentement.']);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent());
        $this->assertObjectNotHasProperty('promptMobile', $body);
        $this->assertStringContainsString('Format : landscape', FakeLandingAiClient::$requests[0]['messages'][0]['content'], 'valeurs par défaut');
    }

    public function testVideoPromptRefusesAnInvalidRequestBeforeAnyAiCall(): void
    {
        foreach ([
            [['prompt' => ''], 'prompt'],
            [['prompt' => 'Un vase.', 'format' => 'carré'], 'format'],
            [['prompt' => 'Un vase.', 'duration' => 7], 'duration'],
            [['prompt' => 'Un vase.', 'textSide' => 'top'], 'textSide'],
            [['prompt' => 'Un vase.', 'background' => 'bleu'], 'background'],
        ] as [$body, $path]) {
            $response = $this->videoPrompt($body);
            $this->assertSame(400, $response->getStatusCode(), $path);
            $this->assertSame($path, json_decode($response->getContent())->errors[0]->path);
        }
        $this->assertSame([], FakeLandingAiClient::$requests);
        $this->assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM ai_usage'), 'aucune réservation');
    }

    public function testVideoPromptFailureReleasesTheCredit(): void
    {
        for ($i = 0; $i < 3; $i++) {
            FakeLandingAiClient::$queue[] = FakeLandingAiClient::videoPromptResponse(['prompt' => '', 'promptMobile' => null, 'steps' => [], 'notes' => []]);
        }

        $response = $this->videoPrompt(['prompt' => 'Un vase qui tourne lentement.']);

        $this->assertSame(502, $response->getStatusCode(), $response->getContent());
        $this->assertSame(0, json_decode($this->get('/api/landingpage-ai/usage')->getContent())->credits->used);
        $this->assertStringContainsString('prompt, promptMobile, les étapes', FakeLandingAiClient::$requests[1]['messages'][2]['content'][0]['content']);
    }

    public function testScrollSceneNeedsAVideoFileAndIsUniquePerSection(): void
    {
        $checker = static::getContainer()->get(\App\Services\LandingAiService\LandingAiCompositionChecker::class);
        $scene = fn (array $video, string $id = 'scene') => [
            ['id' => $id, 'type' => 'container', 'parentId' => null, 'layout' => 'scroll', 'scrollLength' => 4],
            ['id' => "$id-video", 'parentId' => $id] + $video,
            ['id' => "$id-etape", 'type' => 'text', 'parentId' => $id, 'stepAt' => 10],
        ];
        $errors = fn (array $blocks, array $skip = []) => array_column($checker->sceneErrors(json_decode(json_encode(['schemaVersion' => 2, 'blocks' => $blocks]), false), $skip), 'message');

        $this->assertSame([], $errors($scene(['type' => 'video', 'url' => 'https://media.example.com/film.mp4'])));
        $this->assertSame([], $errors($scene(['type' => 'video', 'mediaKey' => str_repeat('a', 64)])));
        $this->assertSame([], $errors($scene(['type' => 'video', 'bindings' => ['url' => 'videoUrl']])));
        $this->assertStringContainsString('premier enfant', $errors($scene(['type' => 'title', 'text' => 'Titre']))[0]);
        $this->assertStringContainsString('sans fichier vidéo', $errors($scene(['type' => 'video']))[0]);
        $this->assertStringContainsString('YouTube ou Vimeo', $errors($scene(['type' => 'video', 'url' => 'https://www.youtube.com/watch?v=abc']))[0]);

        // second bloc video : version téléphone (9:16), un fichier elle aussi ; jamais trois
        $mobile = fn (array $video, string $id = 'scene-mobile') => ['id' => $id, 'type' => 'video', 'parentId' => 'scene'] + $video;
        $withMobile = [...$scene(['type' => 'video', 'url' => 'https://media.example.com/film.mp4']), $mobile(['mediaKey' => str_repeat('b', 64)])];
        $this->assertSame([], $errors($withMobile));
        $this->assertStringContainsString('version téléphone', $errors([...$scene(['type' => 'video', 'url' => 'https://media.example.com/film.mp4']), $mobile(['url' => 'https://vimeo.com/123'])])[0]);
        $this->assertStringContainsString('version téléphone', $errors([...$scene(['type' => 'video', 'url' => 'https://media.example.com/film.mp4']), $mobile([])])[0]);
        $this->assertStringContainsString('deux blocs video au plus', $errors([...$withMobile, $mobile(['url' => 'https://media.example.com/film.mp4'], 'scene-video-3')])[0]);

        $two = [...$scene(['type' => 'video', 'url' => 'https://media.example.com/film.mp4']), ...$scene(['type' => 'video', 'url' => 'https://media.example.com/film.mp4'], 'autre')];
        $this->assertStringContainsString('une seule scène', $errors($two)[0]);
        $this->assertSame([], $errors($scene(['type' => 'video']), ['scene']), 'retouche : une scène déjà présente n\'est pas une erreur de l\'IA');
    }

    public function testLateStepsOfAScrollSceneAreSpreadEvenly(): void
    {
        $repair = static::getContainer()->get(\App\Services\LandingAiService\LandingAiOutputRepair::class);
        $scene = fn (array $moments) => json_decode(json_encode(['schemaVersion' => 2, 'blocks' => [
            ['id' => 'scene', 'type' => 'container', 'parentId' => null, 'layout' => 'scroll'],
            ['id' => 'film', 'type' => 'video', 'parentId' => 'scene'],
            ...array_map(fn ($at, $i) => ['id' => "etape-$i", 'type' => 'container', 'parentId' => 'scene', 'stepAt' => $at], $moments, array_keys($moments)),
        ]]), false);

        $late = $scene([0, 33, 66, 100]);
        $this->assertCount(4, $repair->dropLateSteps($late), 'une étape après 85 % : tous les stepAt de la scène sont retirés');
        $this->assertSame([], array_filter($late->blocks, fn ($b) => property_exists($b, 'stepAt')));

        $fine = $scene([0, 30, 60, 85]);
        $this->assertSame([], $repair->dropLateSteps($fine));
        $this->assertSame(85, $fine->blocks[5]->stepAt);
        $this->assertSame([], $repair->dropLateSteps($scene([0, 50, 100]), ['scene']), 'retouche : scène de départ laissée telle quelle');
    }

    public function testVideoDataTellsAFileFromAStreamingLink(): void
    {
        $kind = \App\Services\LandingAiService\LandingAiDataSources::videoKind(...);
        $this->assertSame('fichier vidéo', $kind('/assets/uploads/videos/film.mp4'));
        $this->assertSame('vidéo YouTube ou Vimeo', $kind('https://www.youtube.com/watch?v=abc'));
        $this->assertSame('vidéo YouTube ou Vimeo', $kind('https://vimeo.com/123456'));
        $this->assertSame('aucune vidéo', $kind(''));
    }

    public function testScrollSceneRuleIsSentOnlyWhenTheContractKnowsTheLayout(): void
    {
        $with = json_decode('{"$defs":{"block":{"properties":{"layout":{"enum":["free","stack","row","grid","slides","scroll"]}}}}}');
        $without = json_decode('{"$defs":{"block":{"properties":{"layout":{"enum":["free","stack","row","grid","slides"]}}}}}');
        $builder = \App\Services\LandingAiService\LandingAiPromptBuilder::class;
        $this->assertTrue($builder::supportsScrollScene($with));
        $this->assertFalse($builder::supportsScrollScene($without));

        $schema = json_decode(file_get_contents(static::getContainer()->get(\App\Services\LandingConfigService\LandingConfigStore::class)->path(\App\Services\LandingConfigService\LandingConfigStore::SCHEMA)));
        $payload = static::getContainer()->get($builder)
            ->editPayload('claude-sonnet-5', 'PresentationGroup', $this->preset('group-type-t'), 'Titre en noir.', 'fr', [], [], ['colors' => [], 'fonts' => []]);
        $this->assertSame($builder::supportsScrollScene($schema), str_contains($payload['system'][0]['text'], 'Scène au défilement'), 'consigne présente si et seulement si le contrat actif connaît le layout');
    }

    public function testSonnet55GetsAutomaticToolChoice(): void
    {
        $payload = static::getContainer()->get(\App\Services\LandingAiService\LandingAiPromptBuilder::class)
            ->editPayload('claude-sonnet-5-5', 'PresentationGroup', $this->preset('group-type-t'), 'Titre en noir.', 'fr', [], [], ['colors' => [], 'fonts' => []]);

        $this->assertSame(['type' => 'auto'], $payload['tool_choice']);
    }

    public function testTooManyFailuresInADaySuspendTheAssistantOnThisSite(): void
    {
        $insert = "INSERT INTO ai_usage (tenant, created_at, reserved_until, mode, component_key, status, credits, attempts) VALUES ('mvtest', NOW() - INTERVAL '2 hours', NOW(), 'edit', 'PresentationGroup', 'failed', 1, %d)";
        for ($i = 0; $i < 5; $i++) {
            $this->db()->executeStatement(sprintf($insert, 0)); // échecs sans appel à l'IA : non comptés
        }
        for ($i = 0; $i < \App\Services\LandingAiService\LandingAiQuotaService::FAILED_REQUESTS_PER_DAY - 1; $i++) {
            $this->db()->executeStatement(sprintf($insert, 3));
        }
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::editResponse([['op' => 'update', 'id' => 't-titre', 'set' => ['color' => '#000000']]]);
        $body = ['mode' => 'edit', 'componentKey' => 'PresentationGroup', 'composition' => $this->preset('group-type-t'), 'prompt' => 'Titre en noir.'];
        $this->assertSame(200, $this->compose($body)->getStatusCode(), 'sous le plafond');

        $this->db()->executeStatement(sprintf($insert, 3));
        $response = $this->compose($body);

        $this->assertSame(429, $response->getStatusCode(), $response->getContent());
        $this->assertSame('Trop d\'échecs', json_decode($response->getContent())->error);
        $this->assertGreaterThan(20 * 3600, (int) $response->headers->get('Retry-After'), 'jusqu\'à la sortie du plus ancien échec de la fenêtre de 24 h');
        $this->assertCount(1, FakeLandingAiClient::$requests, 'aucun appel après le plafond');
    }

    public function testVisualReviewSendsBothCapturesAsTheCurrentRendering(): void
    {
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::editResponse([['op' => 'update', 'id' => 't-titre', 'set' => ['color' => '#000000']]], 'Contraste du titre renforcé.');
        $jpeg = $this->jpegDataUrl();

        $job = $this->composeInBackground(['mode' => 'edit', 'componentKey' => 'PresentationGroup', 'composition' => $this->preset('group-type-t'), 'prompt' => 'Relecture visuelle : surtout le mobile.', 'images' => [$jpeg, $jpeg]]);

        $this->assertSame('done', $job->status);
        $this->assertSame(10, $job->result->credits->used);
        $content = FakeLandingAiClient::$requests[0]['messages'][0]['content'];
        $this->assertSame(['image', 'image', 'text'], array_column($content, 'type'), 'captures avant le texte');
        $this->assertSame('image/jpeg', $content[0]['source']['media_type']);
        $this->assertStringContainsString('rendu actuel de cette section tel qu\'un visiteur la voit (1re : ordinateur, 1280 px de large ; 2e : mobile, 390 px)', $content[2]['text']);
        $this->assertStringContainsString('Relecture visuelle (retouche', FakeLandingAiClient::$requests[0]['system'][0]['text']);
    }

    public function testFailedJobReleasesCreditsAndReportsTheError(): void
    {
        $invalid = FakeLandingAiClient::pageResponse([['componentKey' => 'Inconnue', 'dataType' => null, 'composition' => new \stdClass()]]);
        FakeLandingAiClient::$queue = [$invalid, $invalid, $invalid];

        $response = $this->compose(['mode' => 'page', 'prompt' => 'Une page.']);
        $this->assertSame(10, json_decode($response->getContent())->credits->used, 'crédits réservés pendant la tâche');
        $jobId = json_decode($response->getContent())->jobId;
        $this->runWorker($this->sentJobMessages());

        $job = json_decode($this->get('/api/landingpage-ai/jobs/' . $jobId)->getContent(), false);
        $this->assertSame('failed', $job->status);
        $this->assertObjectNotHasProperty('result', $job);
        $this->assertSame(502, $job->error->status);
        $this->assertSame('Proposition invalide', $job->error->error);
        $this->assertSame('sections[0].componentKey', $job->error->errors[0]->path);
        $usage = json_decode($this->get('/api/landingpage-ai/usage')->getContent(), true);
        $this->assertSame(0, $usage['credits']['used'], 'crédits libérés');
        $this->assertSame('failed', $usage['history'][0]['status']);
        $this->assertNull($this->db()->fetchOne('SELECT input FROM ai_job'), 'requête effacée après le traitement');
    }

    public function testJobIsOnlyVisibleToItsTenant(): void
    {
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::pageResponse([['componentKey' => 'Contact', 'dataType' => null, 'composition' => $this->preset('contact-type-a')]]);
        $jobId = json_decode($this->compose(['mode' => 'page', 'prompt' => 'Un contact.'])->getContent())->jobId;
        $this->runWorker($this->sentJobMessages());
        $this->assertSame(200, $this->get('/api/landingpage-ai/jobs/' . $jobId)->getStatusCode());

        $this->tenant = [MV_TEST_TENANT2_HOST, MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE];
        $this->session = $this->login(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        $response = $this->get('/api/landingpage-ai/jobs/' . $jobId);
        $this->assertSame(404, $response->getStatusCode(), 'tâche d\'un autre tenant');
        $this->assertSame('Tâche introuvable', json_decode($response->getContent())->error);

        $this->assertSame(404, $this->get('/api/landingpage-ai/jobs/00000000-0000-4000-8000-000000000000')->getStatusCode());
        $this->assertSame(404, $this->get('/api/landingpage-ai/jobs/pas-un-identifiant')->getStatusCode());
    }

    public function testNonAdminCannotReadAJob(): void
    {
        $this->session = $this->login(['ROLE_USER_INTERNET']);
        $this->assertSame(403, $this->get('/api/landingpage-ai/jobs/00000000-0000-4000-8000-000000000000')->getStatusCode());
    }

    public function testMessageDeliveredTwiceCallsTheAiOnce(): void
    {
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::pageResponse([['componentKey' => 'Contact', 'dataType' => null, 'composition' => $this->preset('contact-type-a')]]);
        $this->compose(['mode' => 'page', 'prompt' => 'Un contact.']);
        $messages = $this->sentJobMessages();

        $this->runWorker([...$messages, ...$messages]);

        $this->assertCount(1, FakeLandingAiClient::$requests);
        $this->assertSame('done', $this->db()->fetchOne('SELECT status FROM ai_job'));
    }

    public function testStuckJobFailsAndReleasesCredits(): void
    {
        $jobId = json_decode($this->compose(['mode' => 'page', 'prompt' => 'Une page.'])->getContent())->jobId;
        $reservedFor = (int) $this->db()->fetchOne('SELECT EXTRACT(EPOCH FROM reserved_until - created_at) FROM ai_usage');
        $this->assertSame(\App\Services\LandingAiService\LandingAiQuotaService::JOB_RESERVATION_TTL_SECONDS, $reservedFor, 'réservation plus longue qu\'en synchrone');

        // worker arrêté : la tâche attend depuis 31 minutes
        $this->db()->executeStatement("UPDATE ai_job SET created_at = NOW() - INTERVAL '31 minutes'");
        $job = json_decode($this->get('/api/landingpage-ai/jobs/' . $jobId)->getContent(), false);

        $this->assertSame('failed', $job->status);
        $this->assertSame(504, $job->error->status);
        $this->assertSame(0, json_decode($this->get('/api/landingpage-ai/usage')->getContent())->credits->used);
        $this->runWorker($this->sentJobMessages());
        $this->assertSame([], FakeLandingAiClient::$requests, 'une tâche en échec n\'est plus traitée');
    }

    public function testFinishedJobExpiresAfterOneHour(): void
    {
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::pageResponse([['componentKey' => 'Contact', 'dataType' => null, 'composition' => $this->preset('contact-type-a')]]);
        $jobId = json_decode($this->compose(['mode' => 'page', 'prompt' => 'Un contact.'])->getContent())->jobId;
        $this->runWorker($this->sentJobMessages());

        $this->db()->executeStatement("UPDATE ai_job SET finished_at = NOW() - INTERVAL '61 minutes'");

        $this->assertSame(404, $this->get('/api/landingpage-ai/jobs/' . $jobId)->getStatusCode());
        $this->assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM ai_job'));
    }

    /**
     * Mode page ou images : POST (202 { jobId, credits }), passage du worker, puis lecture de la tâche.
     *
     * @return object { jobId, status, result? , error? }
     */
    private function composeInBackground(array $body): object
    {
        $response = $this->compose($body);
        $this->assertSame(202, $response->getStatusCode(), $response->getContent());
        $accepted = json_decode($response->getContent(), false);
        $this->assertSame('/api/landingpage-ai/jobs/' . $accepted->jobId, $response->headers->get('Location'));
        $this->assertSame([], FakeLandingAiClient::$requests, 'aucun appel à l\'IA avant le passage du worker');
        $messages = $this->sentJobMessages();
        $this->assertCount(1, $messages);
        $this->assertSame('pending', json_decode($this->get('/api/landingpage-ai/jobs/' . $accepted->jobId)->getContent())->status);

        $this->runWorker($messages);

        $job = $this->get('/api/landingpage-ai/jobs/' . $accepted->jobId);
        $this->assertSame(200, $job->getStatusCode(), $job->getContent());

        return json_decode($job->getContent(), false);
    }

    /**
     * Messages de tâches envoyés par la dernière requête (transport en mémoire). À lire avant la requête suivante :
     * le client de test redémarre le noyau, et donc le transport, à chaque requête.
     *
     * @return list<\App\Message\LandingAiJobMessage>
     */
    private function sentJobMessages(): array
    {
        return array_values(array_filter(
            array_map(fn ($envelope) => $envelope->getMessage(), static::getContainer()->get('messenger.transport.landing_ai')->getSent()),
            fn ($message) => $message instanceof \App\Message\LandingAiJobMessage
        ));
    }

    /** Traite les messages comme le worker Messenger */
    private function runWorker(array $messages): void
    {
        $handler = static::getContainer()->get(\App\MessageHandler\LandingAiJobHandler::class);
        foreach ($messages as $message) {
            $handler($message);
        }
    }

    /** Petite image JPEG valide (format des captures de la relecture visuelle), en data URL */
    private function jpegDataUrl(): string
    {
        $image = imagecreatetruecolor(8, 8);
        imagefill($image, 0, 0, imagecolorallocate($image, 250, 250, 250));
        ob_start();
        imagejpeg($image);

        return 'data:image/jpeg;base64,' . base64_encode(ob_get_clean());
    }

    /** Petite image PNG valide, en data URL */
    private function pngDataUrl(): string
    {
        static $url = null;
        if ($url === null) {
            $image = imagecreatetruecolor(4, 4);
            imagefill($image, 0, 0, imagecolorallocate($image, 16, 64, 128));
            ob_start();
            imagepng($image);
            $url = 'data:image/png;base64,' . base64_encode(ob_get_clean());
        }

        return $url;
    }

    /** @return list<string> */
    private function availableIds(string $componentKey): array
    {
        $this->db();

        return static::getContainer()->get(\App\Services\LandingAiService\LandingAiDataSources::class)->availableIds($componentKey);
    }

    /** Test d'intégration réel (cas R1), activé seulement avec LANDING_AI_REAL_TEST=1 : consomme des jetons. */
    public function testRealModelOnCaseR1(): void
    {
        if (!getenv('LANDING_AI_REAL_TEST')) {
            $this->markTestSkipped('Appel réel désactivé (LANDING_AI_REAL_TEST=1 pour l\'activer).');
        }
        $container = static::getContainer();
        $container->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $composer = new \App\Services\LandingAiService\LandingAiComposer(
            new \App\Services\LandingAiService\AnthropicLandingAiClient($container->get(\App\Services\AnthropicService::class), ($_ENV['ANTHROPIC_API_KEY_LANDING'] ?? $_SERVER['ANTHROPIC_API_KEY_LANDING'] ?? null) ?: null),
            $container->get(\App\Services\LandingAiService\LandingAiPromptBuilder::class),
            $container->get(\App\Services\LandingAiService\CompositionEditApplier::class),
            $container->get(\App\Services\LandingAiService\LandingAiCompositionChecker::class),
            $container->get(\App\Services\LandingAiService\LandingAiSiteContext::class),
            $container->get('logger'),
            'claude-sonnet-5'
        );
        $before = $this->preset('group-type-t');

        $result = $composer->edit('PresentationGroup', $before, 'Rends la section plus aérée.');

        $this->assertGreaterThanOrEqual(1, $result->stats->attempts);
        $this->assertSame([], $container->get(\App\Services\LandingPageSettingsService\ReglableCompositionValidator::class)->validateComposition($result->composition));
    }

    private function preset(string $presetId): object
    {
        [, $preset] = static::getContainer()->get(LandingAiCatalogue::class)->preset($presetId);

        return json_decode(json_encode($preset['composition'], JSON_PRESERVE_ZERO_FRACTION), false);
    }

    /** @return array<string, object> */
    private function blocksById(object $composition): array
    {
        $byId = [];
        foreach ($composition->blocks as $block) {
            $byId[$block->id] = $block;
        }

        return $byId;
    }

    private function compose(array $body): Response
    {
        return $this->request('POST', '/api/landingpage-ai/compose', json_encode($body, JSON_PRESERVE_ZERO_FRACTION));
    }

    private function videoPrompt(array $body): Response
    {
        return $this->request('POST', '/api/landingpage-ai/video-prompt', json_encode($body));
    }

    private function get(string $path): Response
    {
        return $this->request('GET', $path);
    }

    private function request(string $method, string $path, ?string $body = null): Response
    {
        $jar = $this->client->getCookieJar();
        $jar->clear();
        foreach ($this->session as $name => $value) {
            $jar->set(new \Symfony\Component\BrowserKit\Cookie($name, $value, null, '/', $this->tenant[0], true));
        }
        $this->client->request($method, 'https://' . $this->tenant[0] . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => $this->tenant[0],
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $this->session['XSRF-TOKEN_' . $this->tenant[2]] ?? null,
        ]), $body);

        return $this->client->getResponse();
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant($this->tenant[1], $this->tenant[2]);

        return $provider->getEntityManager()->getConnection();
    }

    /** @return array<string, string> cookies de session */
    private function login(array $roles): array
    {
        $email = 'landing-ai-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant($this->tenant[1], $this->tenant[2]);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Landing')->setLastname('IA')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . $this->tenant[0] . '/api/login', [], [], [
            'HTTP_X_TENANT_HOST' => $this->tenant[0], 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'web']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $session = [];
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            $session[$cookie->getName()] = $cookie->getValue();
        }

        return $session;
    }
}
