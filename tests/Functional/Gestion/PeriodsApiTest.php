<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\Collections;
use App\Entity\Entreprise;
use App\Entity\Fournisseur;
use App\Entity\TypeFournisseur;
use App\Entity\TypeNoteDeFrais;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Gestion\Services\PeriodCalendar;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Périodes de gestion, lot P1 (10/11/2026, `docs/periodes-gestion.md`) : calendrier (trimestres, semestres, mois), création à la
 * lecture sans trou et idempotente, état par la date (fin + 15 jours), classement par date, 409 PERIOD_CLOSED sur les
 * écritures, réouverture manuelle, budget, combiné par kind, réglage `gestion.periods`.
 */
class PeriodsApiTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private KernelBrowser $client;
    private string $token = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->login();
    }

    public function testCalendarDefinitions(): void
    {
        $q = fn (string $d) => PeriodCalendar::definition(new \DateTimeImmutable($d), 'quarterly');
        $this->assertSame(['2026-Q2', 'Printemps 2026', '2026-03-01', '2026-05-31'], [$q('2026-04-10')['key'], $q('2026-04-10')['name'], $q('2026-04-10')['start']->format('Y-m-d'), $q('2026-04-10')['end']->format('Y-m-d')]);
        $this->assertSame(['2026-Q3', 'Été 2026'], [$q('2026-06-01')['key'], $q('2026-06-01')['name']]);
        $this->assertSame(['2026-Q4', 'Automne 2026', '2026-11-30'], [$q('2026-11-30')['key'], $q('2026-11-30')['name'], $q('2026-11-30')['end']->format('Y-m-d')]);
        $this->assertSame(['2027-Q1', 'Hiver 2026-2027', '2026-12-01', '2027-02-28'], [$q('2026-12-15')['key'], $q('2026-12-15')['name'], $q('2026-12-15')['start']->format('Y-m-d'), $q('2026-12-15')['end']->format('Y-m-d')], 'décembre appartient à l\'hiver de l\'année suivante');
        $this->assertSame(['2026-Q1', 'Hiver 2025-2026', '2025-12-01', '2026-02-28'], [$q('2026-01-20')['key'], $q('2026-01-20')['name'], $q('2026-01-20')['start']->format('Y-m-d'), $q('2026-01-20')['end']->format('Y-m-d')]);
        $this->assertSame('2028-02-29', PeriodCalendar::definition(new \DateTimeImmutable('2028-01-05'), 'quarterly')['end']->format('Y-m-d'), 'année bissextile');
        $s = PeriodCalendar::definition(new \DateTimeImmutable('2026-09-09'), 'semester');
        $this->assertSame(['2026-S2', 'Semestre 2 2026', '2026-07-01', '2026-12-31'], [$s['key'], $s['name'], $s['start']->format('Y-m-d'), $s['end']->format('Y-m-d')]);
        $m = PeriodCalendar::definition(new \DateTimeImmutable('2026-02-09'), 'monthly');
        $this->assertSame(['2026-M02', 'Février 2026', '2026-02-28'], [$m['key'], $m['name'], $m['end']->format('Y-m-d')]);
    }

    public function testPeriodsAreCreatedOnReadWithoutGapsAndClosedByDate(): void
    {
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $today = $calendar->today();
        $periods = $this->get('/api/periodes');
        $this->assertNotEmpty($periods);
        foreach ($periods as $p) {
            $this->assertSame('period', $p['kind']);
            $this->assertNotNull($p['periodKey']);
            $this->assertContains($p['status'], ['open', 'closed']);
        }
        $current = $this->get('/api/periodes/courante');
        $this->assertSame(PeriodCalendar::definition($today, 'quarterly')['key'], $current['periodKey']);
        $this->assertSame('open', $current['status']);
        $this->assertNull($current['closedAt']);
        $keys = array_column($periods, 'periodKey');
        $this->assertContains($current['periodKey'], $keys);
        $next = PeriodCalendar::definition(PeriodCalendar::definition($today, 'quarterly')['end']->modify('+1 day'), 'quarterly')['key'];
        $this->assertContains($next, $keys, 'la période suivante existe déjà');

        // Pas de trou : toutes les clés entre la plus ancienne et la suivante se suivent
        $sorted = array_values(array_filter($periods, fn ($p) => $p['kind'] === 'period'));
        usort($sorted, fn ($a, $b) => strcmp($a['startDateCollection'], $b['startDateCollection']));
        for ($i = 1; $i < count($sorted); $i++) {
            $prevEnd = (new \DateTimeImmutable($sorted[$i - 1]['endDateCollection']))->modify('+1 day')->format('Y-m-d');
            $this->assertSame($prevEnd, substr($sorted[$i]['startDateCollection'], 0, 10), 'périodes contiguës');
        }

        // Idempotence : une seconde lecture (et une création directe) ne crée rien de plus
        $countBefore = count($this->get('/api/periodes?status=all'));
        $this->assertSame(0, $calendar->ensurePeriods());
        $this->assertSame($countBefore, count($this->get('/api/periodes?status=all')));
        $this->assertSame(0, $this->em()->getConnection()->fetchOne("SELECT COUNT(*) - COUNT(DISTINCT period_key) FROM collections WHERE kind = 'period'"), 'aucune clé en double');

        // Une période échue depuis plus de 15 jours est fermée ; celle d'aujourd'hui est ouverte ; la liste filtre par statut
        $old = $calendar->periodFor($today->modify('-200 days'));
        $this->assertNotNull($old);
        $this->assertTrue($calendar->isClosed($old), 'période d\'il y a 200 jours : fermée par la date');
        $closedKeys = array_column($this->get('/api/periodes?status=closed'), 'periodKey');
        $openKeys = array_column($this->get('/api/periodes?status=open'), 'periodKey');
        $this->assertContains($old->getPeriodKey(), $closedKeys);
        $this->assertContains($current['periodKey'], $openKeys);
        $this->assertNotContains($current['periodKey'], $closedKeys);

        // GET /api/collections rend manuelles et périodes avec leur kind ; ?kind=manual exclut les périodes
        $manual = $this->create(Collections::class, ['nomCollection' => 'Manuelle ' . bin2hex(random_bytes(2)), 'del' => false, 'isClosed' => false]);
        $this->endOfWrite();
        $all = $this->get('/api/collections');
        $kinds = array_unique(array_column($all, 'kind'));
        $this->assertContains('manual', $kinds);
        $this->assertContains('period', $kinds);
        $this->assertSame(['manual'], array_values(array_unique(array_column($this->get('/api/collections?kind=manual'), 'kind'))));
        $this->assertContains($manual->getId(), array_map('intval', array_column($this->get('/api/collections?kind=manual'), 'id')));
    }

    public function testWritesAreClassifiedByDateAndRefusedInClosedPeriods(): void
    {
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $today = $calendar->today();
        $type = $this->create(TypeFournisseur::class, ['name' => 'Type P1 ' . bin2hex(random_bytes(2))]);
        $noteType = $this->create(TypeNoteDeFrais::class, ['name' => 'Note P1 ' . bin2hex(random_bytes(2))]);
        $fournisseur = $this->create(Fournisseur::class, ['name' => 'Fournisseur P1 ' . bin2hex(random_bytes(2))], fn (Fournisseur $f, EntityManagerInterface $em) => $f->setTypeFournisseur($em->find(TypeFournisseur::class, $type->getId())));
        $this->endOfWrite();
        $this->get('/api/periodes'); // calendrier en place

        // Commande créée par sa date (aujourd'hui) : classée dans la période courante
        $commande = $this->post('/api/commandes', ['budget' => 100000, 'date' => $today->format('Y-m-d 10:00:00'), 'name' => 'Par date', 'fournisseur' => ['id' => $fournisseur->getId()]], 201);
        $current = $this->get('/api/periodes/courante');
        $this->assertSame((int) $current['id'], $commande['collectionId'], 'période de la date');
        $inPeriod = array_map('intval', array_column($this->get('/api/collections/' . $current['id'] . '/commandes'), 'id'));
        $this->assertContains((int) $commande['id'], $inPeriod);

        // Dans une période fermée (il y a 200 jours) : refusé, avec l'identifiant de la période
        $oldDay = $today->modify('-200 days');
        $old = $calendar->periodFor($oldDay);
        $this->request('POST', '/api/commandes', ['budget' => 1, 'date' => $oldDay->format('Y-m-d 10:00:00'), 'name' => 'Trop tard', 'fournisseur' => ['id' => $fournisseur->getId()]]);
        $body = $this->body();
        $this->assertSame([409, 'PERIOD_CLOSED', $old->getId(), $old->getPeriodKey()], [$this->client->getResponse()->getStatusCode(), $body['error'], $body['periodId'], $body['periodKey']]);
        $this->request('POST', '/api/notes-de-frais', ['description' => 'Trop tard', 'montant' => 100, 'date' => $oldDay->format('Y-m-d'), 'typeNoteDeFraisId' => $noteType->getId()]);
        $this->assertSame([409, 'PERIOD_CLOSED'], [$this->client->getResponse()->getStatusCode(), $this->body()['error']]);
        $this->request('POST', '/api/collections/' . $old->getId() . '/commandes', ['budget' => 1, 'date' => $oldDay->format('Y-m-d 10:00:00'), 'name' => 'Trop tard', 'fournisseur' => ['id' => $fournisseur->getId()]]);
        $this->assertSame([409, 'PERIOD_CLOSED'], [$this->client->getResponse()->getStatusCode(), $this->body()['error']], 'route par collection : même refus');

        // Déplacer la commande du jour vers la période fermée : refusé ; vers un autre jour ouvert : accepté
        $this->request('PATCH', '/api/commandes/' . $commande['id'], ['date' => $oldDay->format('Y-m-d 10:00:00')]);
        $this->assertSame([409, 'PERIOD_CLOSED'], [$this->client->getResponse()->getStatusCode(), $this->body()['error']]);
        $this->request('PATCH', '/api/commandes/' . $commande['id'], ['budget' => 120000]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        // Note par date dans la période courante, puis budget de la période et période non supprimable
        $expensesBefore = (int) $this->get('/api/dashboard/collection/' . $current['id'])['GeneralExpenses']['totalExpenseCost']; // base partagée : en différence
        $note = $this->post('/api/notes-de-frais', ['description' => 'Essence', 'montant' => 2500, 'date' => $today->format('Y-m-d'), 'typeNoteDeFraisId' => $noteType->getId()], 201);
        $this->assertSame(2500, $note['montant']);
        $this->request('PATCH', '/api/periodes/' . $current['id'], ['budget' => 777777]);
        $this->assertSame([200, 777777], [$this->client->getResponse()->getStatusCode(), $this->body()['budgetCollection']]);
        $this->request('PATCH', '/api/periodes/' . $old->getId(), ['budget' => 1]);
        $this->assertSame([409, 'PERIOD_CLOSED'], [$this->client->getResponse()->getStatusCode(), $this->body()['error']]);
        $this->request('DELETE', '/api/collections/' . $current['id']);
        $this->assertSame([409, 'PERIOD_NOT_DELETABLE'], [$this->client->getResponse()->getStatusCode(), $this->body()['error']]);

        // Tableau de bord de la période par date : la commande et la note du jour y sont ; figé pour la période fermée ; combiné par kind
        $dashboard = $this->get('/api/dashboard/collection/' . $current['id']);
        $this->assertSame([777777, $expensesBefore + 2500], [$dashboard['BudgetGeneral']['generalBudget'], $dashboard['GeneralExpenses']['totalExpenseCost']]);
        $this->assertGreaterThanOrEqual(1, $dashboard['Statistics']['orderCount']);
        $frozen = $this->get('/api/dashboard/collection/' . $old->getId());
        $this->assertArrayHasKey('Statistics', $frozen, 'période fermée : figée à la lecture');
        $this->assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM collection_statistiques WHERE collection_id = ?', [$old->getId()]), 'statistiques figées enregistrées à la première lecture');
        $this->assertSame('manual', $this->get('/api/dashboard/collections-combined')['kind']);
        $combined = $this->get('/api/dashboard/collections-combined?kind=period');
        $this->assertSame('period', $combined['kind']);
        $this->assertGreaterThanOrEqual(2500, $combined['GeneralExpenses']['totalExpenseCost']);
        $this->request('GET', '/api/dashboard/collections-combined?kind=both');
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());

        // Clôture manuelle de la période courante, puis réouverture
        $this->request('POST', '/api/dashboard/collection/' . $current['id'] . '/close');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->assertSame('closed', $this->get('/api/periodes/courante')['status']);
        $this->request('POST', '/api/commandes', ['budget' => 1, 'date' => $today->format('Y-m-d 11:00:00'), 'name' => 'Après clôture', 'fournisseur' => ['id' => $fournisseur->getId()]]);
        $this->assertSame([409, 'PERIOD_CLOSED'], [$this->client->getResponse()->getStatusCode(), $this->body()['error']], 'clôture manuelle : écriture refusée');
        $this->request('POST', '/api/dashboard/collection/' . $current['id'] . '/reopen');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $reopened = $this->get('/api/periodes/courante');
        $this->assertSame(['open', null], [$reopened['status'], $reopened['closedAt']]);
        $this->assertNotNull($reopened['reopenedAt']);
        $this->request('POST', '/api/dashboard/collection/' . $current['id'] . '/reopen');
        $this->assertSame([409, 'NOT_CLOSED'], [$this->client->getResponse()->getStatusCode(), $this->body()['error']]);
        // Une période fermée par la date se rouvre aussi, et reste ouverte malgré la date
        $this->request('POST', '/api/dashboard/collection/' . $old->getId() . '/reopen');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->post('/api/commandes', ['budget' => 1, 'date' => $oldDay->format('Y-m-d 10:00:00'), 'name' => 'Rouverte', 'fournisseur' => ['id' => $fournisseur->getId()]], 201);
    }

    public function testFrequencySettingIsExposedAndApplied(): void
    {
        $entry = null;
        foreach ($this->get('/api/site/features')['features'] as $row) {
            if (($row['key'] ?? null) === 'gestion.periods') {
                $entry = $row;
            }
        }
        $this->assertNotNull($entry, 'clé gestion.periods du registre');
        $this->assertSame(['site', 'enum', ['quarterly', 'semester', 'monthly']], [$entry['scope'], $entry['type'], $entry['options']]);
        $this->assertSame('quarterly', $entry['effective']);

        $this->request('PUT', '/api/site/features', ['values' => ['gestion.periods' => 'weekly']]);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->request('PUT', '/api/site/features', ['values' => ['gestion.periods' => 'monthly']]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $e = $this->em()->getRepository(Entreprise::class)->findOneBy([], ['id' => 'ASC']);
        $this->assertSame('monthly', $e->getPurchasePeriodFrequency());
        // Les périodes existantes gardent leurs dates ; les suivantes suivent la nouvelle fréquence
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $this->assertSame('monthly', $calendar->frequency());
        $this->request('PUT', '/api/site/features', ['values' => ['gestion.periods' => null]]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->em()->clear();
        $this->assertSame('quarterly', $calendar->frequency(), 'vide : trimestres');
    }

    // ----- helpers -----

    private function body(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function headers(): array
    {
        return ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token, 'HTTP_X_XSRF_TOKEN' => 'jeton-xsrf-de-test'];
    }

    private function request(string $method, string $route, ?array $body = null): void
    {
        $this->client->request($method, $route, [], [], $this->headers(), $body === null ? null : json_encode($body));
    }

    private function get(string $route): array
    {
        $this->request('GET', $route);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 300));

        return $this->body();
    }

    private function post(string $route, array $body, int $expected): array
    {
        $this->request('POST', $route, $body);
        $this->assertSame($expected, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 300));

        return $this->body();
    }

    protected function em(): EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }

    private function endOfWrite(): void
    {
        static::getContainer()->get(CacheInvalidationSubscriber::class)->invalidateWrittenTenants();
    }

    private function login(): void
    {
        $email = 'gestion-p1-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('P1')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
