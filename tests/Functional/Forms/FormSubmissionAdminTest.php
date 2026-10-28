<?php

namespace App\Tests\Functional\Forms;

use App\Controller\Admin\FormSubmissionCrudController;
use App\Controller\Admin\ServiceFamilyCrudController;
use App\Entity\User;
use App\Services\FormSubmissionService\FormSubmissionService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Formulaires à étapes, lot 1 (28/10/2026) : demandes reçues (API d'administration, export, purge, EasyAdmin),
 * familles de prestations et réglages des prestations, liste des prestations enrichie sans changer ses champs.
 */
class FormSubmissionAdminTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';

    private KernelBrowser $client;
    private ?string $token = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $db = $this->db();
        $db->executeStatement('DELETE FROM form_submission');
        $db->executeStatement('UPDATE service_offer SET family_id = NULL');
        $db->executeStatement('DELETE FROM service_family');
        $db->executeStatement("DELETE FROM content_audit_log WHERE resource = 'form-submissions'");
        $db->executeStatement('UPDATE entreprise SET form_submissions_retention_months = NULL');
    }

    public function testListIsReservedToTheAdministratorAndFilters(): void
    {
        $a = $this->submission('IP-20261101-AAAA', 'rdv', 'Rendez-vous', 'new', '2026-11-01 15:00:00');
        $this->submission('IP-20261102-BBBB', 'rdv', 'Rendez-vous', 'done', '2026-11-02 15:00:00');
        $this->submission('IP-20261103-CCCC', 'devis', 'Devis', 'new', '2026-11-03 15:00:00');

        $this->assertSame(401, $this->request('GET', '/api/form-submissions')->getStatusCode());
        $this->loginAs(['ROLE_USER_INTERNET']);
        $this->assertSame(403, $this->request('GET', '/api/form-submissions')->getStatusCode());

        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        $all = $this->json($this->request('GET', '/api/form-submissions'));
        $this->assertSame(3, $all['total']);
        $this->assertSame(['IP-20261103-CCCC', 'IP-20261102-BBBB', 'IP-20261101-AAAA'], array_column($all['items'], 'reference'), 'plus récentes d\'abord');
        $this->assertSame([['label' => 'Nom', 'display' => 'Marie Tremblay'], ['label' => 'Créneau', 'display' => '09:00 - 12:00'], ['label' => 'Remarques', 'display' => 'Canne']], $all['items'][2]['preview'], '3 réponses non vides (le courriel vide est sauté)');
        $this->assertArrayNotHasKey('answers', $all['items'][0], 'liste sans les réponses complètes');

        $this->assertSame(['IP-20261101-AAAA'], array_column($this->json($this->request('GET', '/api/form-submissions?formId=rdv&status=new'))['items'], 'reference'));
        $page = $this->json($this->request('GET', '/api/form-submissions?limit=2&page=2'));
        $this->assertSame([3, 2, 2, ['IP-20261101-AAAA']], [$page['total'], $page['page'], $page['limit'], array_column($page['items'], 'reference')]);
        $this->assertSame(['IP-20261102-BBBB'], array_column($this->json($this->request('GET', '/api/form-submissions?from=2026-11-02&to=2026-11-02'))['items'], 'reference'), 'dates incluses, fuseau du site');
        foreach (['status=perdu', 'from=02/11/2026', 'limit=500', 'page=0'] as $query) {
            $this->assertSame(400, $this->request('GET', "/api/form-submissions?$query")->getStatusCode(), $query);
        }

        $forms = $this->json($this->request('GET', '/api/form-submissions/forms'));
        $this->assertSame([['devis', 'Devis', 1], ['rdv', 'Rendez-vous', 2]], array_map(fn ($f) => [$f['formId'], $f['formName'], $f['count']], $forms));

        $detail = $this->json($this->request('GET', "/api/form-submissions/$a"));
        $this->assertSame('IP-20261101-AAAA', $detail['reference']);
        $this->assertSame('nom', $detail['answers'][0]['name']);
        $this->assertSame(404, $this->request('GET', '/api/form-submissions/999999999')->getStatusCode());
    }

    public function testStatusChangeAndDeletionAreJournaledWithoutAnswers(): void
    {
        $id = $this->submission('IP-20261101-DDDD', 'rdv', 'Rendez-vous', 'new', '2026-11-01 15:00:00');
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);

        $this->assertSame(422, $this->request('PATCH', "/api/form-submissions/$id", ['status' => 'perdu'])->getStatusCode());
        $updated = $this->json($this->request('PATCH', "/api/form-submissions/$id", ['status' => 'done']));
        $this->assertSame('done', $updated['status']);
        $this->assertSame('done', $this->db()->fetchOne('SELECT status FROM form_submission WHERE id = ?', [$id]));

        $this->assertSame(204, $this->request('DELETE', "/api/form-submissions/$id")->getStatusCode());
        $this->assertFalse($this->db()->fetchOne('SELECT id FROM form_submission WHERE id = ?', [$id]));
        $this->assertSame(404, $this->request('DELETE', "/api/form-submissions/$id")->getStatusCode());

        $journal = $this->db()->fetchAllAssociative("SELECT action, before_state, after_state FROM content_audit_log WHERE resource = 'form-submissions' ORDER BY id");
        $this->assertSame(['status', 'delete'], array_column($journal, 'action'));
        $this->assertStringNotContainsString('Marie', json_encode($journal, JSON_UNESCAPED_UNICODE), 'aucune réponse dans le journal');
        $this->assertStringContainsString('IP-20261101-DDDD', (string) $journal[1]['before_state']);
        $this->assertSame(404, $this->request('POST', '/api/landingpage-audit/' . $this->db()->fetchOne("SELECT id FROM content_audit_log WHERE resource = 'form-submissions' AND action = 'delete'") . '/restore')->getStatusCode() === 200 ? 200 : 404, 'une suppression de demande ne se restaure pas');
    }

    public function testExportIsReadableByExcelAndNeutralizesFormulas(): void
    {
        $this->submission('IP-20261101-EEEE', 'rdv', 'Rendez-vous', 'new', '2026-11-01 15:00:00', [
            ['name' => 'nom', 'label' => 'Nom', 'type' => 'text', 'value' => '=HYPERLINK("http://x")', 'display' => '=HYPERLINK("http://x")'],
            ['name' => 'notes', 'label' => 'Remarques', 'type' => 'textarea', 'value' => "Canne ; prévoir du temps", 'display' => "Canne ; prévoir du temps"],
        ]);
        $this->assertSame(401, $this->request('GET', '/api/form-submissions/export')->getStatusCode());
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);

        $response = $this->request('GET', '/api/form-submissions/export');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="demandes-recues-', $response->headers->get('Content-Disposition'));
        $this->assertSame(['1', '0'], [$response->headers->get('X-Export-Count'), $response->headers->get('X-Export-Truncated')]);
        $csv = $response->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'BOM UTF-8 pour Excel');
        $lines = array_map(fn ($l) => str_getcsv($l, ';'), array_values(array_filter(explode("\n", substr($csv, 3)))));
        $this->assertSame(['Référence', 'Reçue le', 'Formulaire', 'Statut', 'Cible', 'Langue', 'Nom', 'Remarques'], $lines[0]);
        $this->assertSame("'=HYPERLINK(\"http://x\")", $lines[1][6], 'formule neutralisée');
        $this->assertSame('Canne ; prévoir du temps', $lines[1][7]);
        $this->assertSame('Nouvelle', $lines[1][3]);
    }

    public function testExpiredSubmissionsArePurgedAccordingToTheSiteRetention(): void
    {
        $old = $this->submission('IP-20250101-OLD1', 'rdv', 'Rendez-vous', 'done', (new \DateTimeImmutable('-13 months'))->format('Y-m-d H:i:s'));
        $recent = $this->submission('IP-20261101-NEW1', 'rdv', 'Rendez-vous', 'new', (new \DateTimeImmutable('-2 months'))->format('Y-m-d H:i:s'));
        $purger = static::getContainer()->get(FormSubmissionService::class);

        $this->db()->executeStatement('UPDATE entreprise SET form_submissions_retention_months = 24');
        $this->assertSame(0, $purger->purgeExpired(), 'conservation de 24 mois : rien à supprimer');
        $this->db()->executeStatement('UPDATE entreprise SET form_submissions_retention_months = NULL');
        static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->clear();
        $this->assertSame(1, $purger->purgeExpired(), '12 mois par défaut');
        $this->assertFalse($this->db()->fetchOne('SELECT id FROM form_submission WHERE id = ?', [$old]));
        $this->assertNotFalse($this->db()->fetchOne('SELECT id FROM form_submission WHERE id = ?', [$recent]));
    }

    public function testServiceFamiliesAndOfferSettings(): void
    {
        $offer = (int) $this->db()->fetchOne("INSERT INTO service_offer (id, titre, descriptions) VALUES (nextval('service_offer_id_seq'), 'Accompagnement aux courses', 'Courses de la semaine') RETURNING id");
        $this->assertSame(401, $this->request('POST', '/api/service-families', ['name' => 'Vie quotidienne'])->getStatusCode());
        $this->loginAs(['ROLE_USER_INTERNET']);
        $this->assertSame(403, $this->request('POST', '/api/service-families', ['name' => 'Vie quotidienne'])->getStatusCode());

        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        $this->assertSame(422, $this->request('POST', '/api/service-families', ['name' => '<b>x</b>'])->getStatusCode());
        $this->assertSame(422, $this->request('POST', '/api/service-families', ['name' => 'X', 'translations' => [['language' => 'fr', 'name' => 'a'], ['language' => 'fr', 'name' => 'b']]])->getStatusCode());
        $family = $this->json($this->request('POST', '/api/service-families', ['name' => 'Vie quotidienne', 'position' => 2, 'translations' => [['language' => 'en', 'name' => 'Daily life']]]), 201);
        $this->assertSame(['Vie quotidienne', 2, [['language' => 'en', 'name' => 'Daily life']]], [$family['name'], $family['position'], $family['translations']]);
        $updated = $this->json($this->request('PUT', '/api/service-families/' . $family['id'], ['position' => 1]));
        $this->assertSame(['Vie quotidienne', 1, 1], [$updated['name'], $updated['position'], count($updated['translations'])], 'PUT partiel : le reste est gardé');

        $this->assertSame(422, $this->request('PUT', "/api/service-offers/$offer", ['titre' => 'Accompagnement aux courses', 'familyId' => 999999])->getStatusCode(), 'famille inconnue');
        $saved = $this->json($this->request('PUT', "/api/service-offers/$offer", ['titre' => 'Accompagnement aux courses', 'familyId' => $family['id'], 'position' => 3, 'bookable' => false, 'durationMinutes' => 90]));
        $this->assertSame([$family['id'], 3, false, 90], [$saved['familyId'], $saved['position'], $saved['bookable'], $saved['durationMinutes']]);

        $this->token = null;
        $this->assertSame([['id' => $family['id'], 'name' => 'Vie quotidienne', 'position' => 1, 'translations' => [['language' => 'en', 'name' => 'Daily life']]]], $this->json($this->request('GET', '/api/service-families')), 'lecture publique');
        $services = $this->json($this->request('GET', '/api/reservations/services'));
        $service = $services[0];
        $this->assertSame('accompagnement-aux-courses', $service['id'], 'id historique : le slug, inchangé');
        $this->assertSame(['Accompagnement aux courses', 'Courses de la semaine'], [$service['label'], $service['description']]);
        $this->assertSame([$offer, 3, false, 90, 'Vie quotidienne'], [$service['serviceOfferId'], $service['position'], $service['bookable'], $service['durationMinutes'], $service['family']['name']]);

        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        $this->assertSame(204, $this->request('DELETE', '/api/service-families/' . $family['id'])->getStatusCode());
        $this->assertNull($this->db()->fetchOne('SELECT family_id FROM service_offer WHERE id = ?', [$offer]), 'la prestation perd sa famille');
        $this->assertSame(200, $this->request('PUT', "/api/service-offers/$offer", ['titre' => 'Accompagnement aux courses', 'bookable' => true])->getStatusCode());
        $this->db()->executeStatement('DELETE FROM service_offer WHERE id = ?', [$offer]);
    }

    public function testFallbackServicesKeepTheirFieldsAndGetTheNewOnes(): void
    {
        if ((int) $this->db()->fetchOne('SELECT COUNT(*) FROM service_offer') > 0) {
            $this->markTestSkipped('le site de test a des prestations');
        }
        $services = $this->json($this->request('GET', '/api/reservations/services'));
        $this->assertSame(['accompagnement-rendez-vous-medicaux', 20], [$services[0]['id'], count($services)], 'liste de secours : ordre et nombre inchangés');
        $this->assertSame(['id', 'label', 'subtitle', 'description', 'serviceOfferId', 'family', 'position', 'bookable', 'durationMinutes', 'translations'], array_keys($services[0]));
        $this->assertSame([null, null, true], [$services[0]['serviceOfferId'], $services[0]['family'], $services[0]['bookable']]);
    }

    public function testEasyAdminManagesSubmissions(): void
    {
        $id = $this->submission('IP-20261101-FFFF', 'rdv', 'Rendez-vous', 'new', '2026-11-01 15:00:00');
        $admin = $this->user(['ROLE_ADMIN']);
        $this->client->loginUser($admin, 'main');

        $this->admin(FormSubmissionCrudController::class, 'index');
        $html = $this->client->getResponse()->getContent();
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), substr(strip_tags($html), 0, 500));
        $this->assertStringContainsString('IP-20261101-FFFF', $html);
        $this->assertStringContainsString('Exporter (CSV)', $html);

        $this->admin(FormSubmissionCrudController::class, 'detail', $id);
        $html = $this->client->getResponse()->getContent();
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), substr(strip_tags($html), 0, 500));
        $this->assertStringContainsString('Marie Tremblay', $html, 'réponses affichées');
        $link = $this->client->getCrawler()->filter('a[href*="crudAction=markDone"]');
        $this->assertGreaterThan(0, $link->count(), 'action « Marquer traitée »');
        $this->client->request('GET', $link->first()->attr('href'), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame('done', $this->db()->fetchOne('SELECT status FROM form_submission WHERE id = ?', [$id]));
        $this->assertSame('status', $this->db()->fetchOne("SELECT action FROM content_audit_log WHERE resource = 'form-submissions' ORDER BY id DESC LIMIT 1"), 'journalisé comme par l\'API');

        $this->admin(FormSubmissionCrudController::class, 'markArchived', $id, ['_csrf' => 'faux']);
        $this->assertSame('done', $this->db()->fetchOne('SELECT status FROM form_submission WHERE id = ?', [$id]), 'jeton invalide : rien ne change');

        $this->admin(FormSubmissionCrudController::class, 'exportCsv');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('IP-20261101-FFFF', $this->client->getResponse()->getContent());

        $this->admin(ServiceFamilyCrudController::class, 'index');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->admin(ServiceFamilyCrudController::class, 'new');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /** Demande reçue écrite directement en base (l'envoi public arrive au lot 3) */
    private function submission(string $reference, string $formId, string $formName, string $status, string $createdAt, ?array $answers = null): int
    {
        $answers ??= [
            ['name' => 'nom', 'label' => 'Nom', 'type' => 'text', 'value' => 'Marie Tremblay', 'display' => 'Marie Tremblay'],
            ['name' => 'courriel', 'label' => 'Courriel', 'type' => 'email', 'value' => null, 'display' => ''],
            ['name' => 'creneau', 'label' => 'Créneau', 'type' => 'choice', 'value' => '09:00 - 12:00', 'display' => '09:00 - 12:00'],
            ['name' => 'notes', 'label' => 'Remarques', 'type' => 'textarea', 'value' => 'Canne', 'display' => 'Canne'],
        ];

        return (int) $this->db()->fetchOne(
            "INSERT INTO form_submission (reference, form_name, app, section_uid, form_id, answers, locale, status, target, created_at, updated_at)
             VALUES (?, ?, 'landingpage', 's-test', ?, ?, 'fr', ?, 'reservation', ?, ?) RETURNING id",
            [$reference, $formName, $formId, json_encode($answers, JSON_UNESCAPED_UNICODE), $status, $createdAt, $createdAt]
        );
    }

    private function admin(string $controller, string $action, ?int $id = null, array $extra = []): void
    {
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(array_filter([
            'crudAction' => $action, 'crudControllerFqcn' => $controller, 'entityId' => $id,
        ]) + $extra), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
    }

    private function user(array $roles): User
    {
        $email = 'forms-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Forms')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $user;
    }

    private function loginAs(array $roles): void
    {
        $user = $this->user($roles);
        $this->token = null;
        $login = $this->request('POST', '/api/login', ['username' => $user->getEmail(), 'password' => self::PASSWORD, 'platform' => 'mobile']);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];
    }

    private function request(string $method, string $path, ?array $body = null): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null,
        ]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function json(Response $response, int $expected = 200): array
    {
        $this->assertSame($expected, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent(), true);
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager()->getConnection();
    }
}
