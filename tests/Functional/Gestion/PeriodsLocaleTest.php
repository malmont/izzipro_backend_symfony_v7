<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\Collections;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Gestion\Services\PeriodCalendar;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeLandingAiClient;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot LEX volet 2 (12/11/2026) : noms de périodes traduits en anglais (`?locale=en`) sur les cinq services de gestion qui
 * acceptent déjà `?locale`, sauf modification manuelle en base ou collection manuelle, sans mélange de cache ni écriture en base.
 */
class PeriodsLocaleTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private KernelBrowser $client;
    private string $token = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->login();
    }

    public function testDisplayNamePureFunction(): void
    {
        $monthsEn = [
            1 => 'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December',
        ];
        foreach ($monthsEn as $m => $enMonth) {
            $date = new \DateTimeImmutable(sprintf('2026-%02d-10', $m));
            $def = PeriodCalendar::definition($date, 'monthly');
            $period = (new Collections())->setKind(Collections::KIND_PERIOD)->setPeriodKey($def['key'])->setNomCollection($def['name'])->setStartDateCollection(\DateTime::createFromImmutable($def['start']));
            $this->assertSame($def['name'], PeriodCalendar::displayName($period, 'fr'));
            $this->assertSame("$enMonth 2026", PeriodCalendar::displayName($period, 'en'));
        }

        foreach ([['2026-02-01', 'Semestre 1 2026', 'First half 2026'], ['2026-08-01', 'Semestre 2 2026', 'Second half 2026']] as [$d, $frName, $enName]) {
            $def = PeriodCalendar::definition(new \DateTimeImmutable($d), 'semester');
            $this->assertSame($frName, $def['name'], 'definition() garde le libellé français intact');
            $period = (new Collections())->setKind(Collections::KIND_PERIOD)->setPeriodKey($def['key'])->setNomCollection($def['name'])->setStartDateCollection(\DateTime::createFromImmutable($def['start']));
            $this->assertSame($enName, PeriodCalendar::displayName($period, 'en'));
        }

        $seasons = [
            ['2026-04-01', 'Printemps 2026', 'Spring 2026'],
            ['2026-07-01', 'Été 2026', 'Summer 2026'],
            ['2026-10-01', 'Automne 2026', 'Fall 2026'],
            ['2025-12-15', 'Hiver 2025-2026', 'Winter 2025-2026'],
            ['2026-01-20', 'Hiver 2025-2026', 'Winter 2025-2026'],
        ];
        foreach ($seasons as [$d, $frName, $enName]) {
            $def = PeriodCalendar::definition(new \DateTimeImmutable($d), 'quarterly');
            $this->assertSame($frName, $def['name']);
            $period = (new Collections())->setKind(Collections::KIND_PERIOD)->setPeriodKey($def['key'])->setNomCollection($def['name'])->setStartDateCollection(\DateTime::createFromImmutable($def['start']));
            $this->assertSame($enName, PeriodCalendar::displayName($period, 'en'));
            $this->assertStringNotContainsString("\u{00A0}", PeriodCalendar::displayName($period, 'en'), 'aucune espace insécable');
        }

        // Nom modifié par le gérant : rendu tel quel en FR et en EN
        $custom = (new Collections())->setKind(Collections::KIND_PERIOD)->setPeriodKey('2026-Q2')->setNomCollection('Mon Printemps spécial')->setStartDateCollection(new \DateTime('2026-03-01'));
        $this->assertSame(['Mon Printemps spécial', 'Mon Printemps spécial'], [PeriodCalendar::displayName($custom, 'fr'), PeriodCalendar::displayName($custom, 'en')]);

        // Collection manuelle : jamais traduite, même si son nom ressemble à une période
        $manual = (new Collections())->setKind(Collections::KIND_MANUAL)->setNomCollection('Printemps 2026')->setStartDateCollection(new \DateTime('2026-03-01'));
        $this->assertSame(['Printemps 2026', 'Printemps 2026'], [PeriodCalendar::displayName($manual, 'fr'), PeriodCalendar::displayName($manual, 'en')]);
        // Collection qui n'est pas une période : jamais traduite, même si elle portait une clé de période et le nom généré (ajout de la relecture)
        $this->assertSame('Printemps 2026', PeriodCalendar::displayNameFor('Printemps 2026', 'manual', '2026-Q2', new \DateTimeImmutable('2026-03-01'), 'en'));
        $this->assertSame('Spring 2026', PeriodCalendar::displayNameFor('Printemps 2026', \App\Entity\Collections::KIND_PERIOD, '2026-Q2', new \DateTimeImmutable('2026-03-01'), 'en'));
    }

    public function testGestionRoutesRespectLocaleCustomNamesAndCacheIsolation(): void
    {
        FakeLandingAiClient::reset();
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $current = $calendar->current();
        $currentId = (int) $current->getId();
        $originalName = (string) $current->getNomCollection();
        $expectedEnCurrent = PeriodCalendar::displayName($current, 'en');

        $conn = $this->em()->getConnection();
        $createdIds = [];
        try {
            // Période fermée isolée en 2004 (absente de la plage courante) pour vérifier GET /statistiques/annee et l'hiver à cheval
            foreach ([
                ['2003-12-01', '2004-02-29', 'Hiver 2003-2004', '2004-Q1'],
                ['2004-03-01', '2004-05-31', 'Printemps 2004', '2004-Q2'],
            ] as [$start, $end, $name, $key]) {
                $existing = $conn->fetchOne('SELECT id FROM collections WHERE period_key = ?', [$key]);
                if ($existing === false) {
                    $createdIds[] = (int) $conn->fetchOne(
                        "INSERT INTO collections (id, budget_collection, start_date_collection, end_date_collection, del, nom_collection, is_closed, kind, period_key) VALUES (nextval('collections_id_seq'), 0, ?, ?, false, ?, true, 'period', ?) RETURNING id",
                        ["$start 00:00:00", "$end 00:00:00", $name, $key]
                    );
                }
            }
            $q1Id = (int) $conn->fetchOne("SELECT id FROM collections WHERE period_key = '2004-Q1'");

            // 1. Budget
            $budgetFr = $this->get("/api/periodes/$q1Id/budget?locale=fr");
            $budgetEn = $this->get("/api/periodes/$q1Id/budget?locale=en");
            $this->assertSame(['Hiver 2003-2004', 'Winter 2003-2004'], [$budgetFr['period']['name'], $budgetEn['period']['name']]);

            // 2. Préparation estimations
            $estFr = $this->get("/api/gestion/preparation/estimations?periodId=$currentId&locale=fr");
            $estEn = $this->get("/api/gestion/preparation/estimations?periodId=$currentId&locale=en");
            $this->assertSame([$originalName, $expectedEnCurrent], [$estFr['period']['name'], $estEn['period']['name']]);
            // Périodes passées de la moyenne (ajout de la relecture) : mêmes périodes dans les deux langues, nom anglais quand le nom est généré
            $this->assertIsArray($estFr['average'], 'des périodes passées existent : la moyenne est calculée');
            $frPast = array_column($estFr['average']['periods'], 'name', 'id');
            $enPast = array_column($estEn['average']['periods'], 'name', 'id');
            $this->assertSame(array_keys($frPast), array_keys($enPast));
            $this->assertNotSame($frPast, $enPast, 'au moins une période passée porte un nom généré, donc traduit');
            foreach ($frPast as $pastId => $frName) {
                $row = $conn->fetchAssociative('SELECT nom_collection, kind, period_key, start_date_collection FROM collections WHERE id = ?', [$pastId]);
                $this->assertSame($row['nom_collection'], $frName, 'français : le nom en base');
                $this->assertSame(PeriodCalendar::displayNameFor($row['nom_collection'], $row['kind'], $row['period_key'], new \DateTimeImmutable(substr((string) $row['start_date_collection'], 0, 10)), 'en'), $enPast[$pastId]);
            }

            // 3. Préparation avis (cache partagé : FR puis EN puis EN en cache ne se mélangent pas)
            $prepBody = ['periodId' => $currentId, 'sales' => ['method' => 'custom', 'amount' => 10000]];
            $prepAvisFr = $this->post("/api/gestion/preparation/avis?locale=fr", $prepBody);
            $prepAvisEn = $this->post("/api/gestion/preparation/avis?locale=en", $prepBody);
            $prepAvisEnCached = $this->post("/api/gestion/preparation/avis?locale=en", $prepBody);
            $this->assertSame($originalName, $prepAvisFr['simulation']['period']['name']);
            $this->assertSame($expectedEnCurrent, $prepAvisEn['simulation']['period']['name']);
            $this->assertSame($expectedEnCurrent, $prepAvisEn['simulation']['planned'][0]['period']['name']);
            $this->assertSame([false, true, $expectedEnCurrent], [$prepAvisEn['cached'], $prepAvisEnCached['cached'], $prepAvisEnCached['simulation']['period']['name']]);
            foreach ($prepAvisEn['candidates'] ?? [] as $candidate) {
                $this->assertSame($expectedEnCurrent, $candidate['planned'][0]['period']['name']);
            }
            $prepAvisEnStock = $this->post('/api/gestion/preparation/avis?locale=en', [
                'periodId' => $currentId,
                'sales' => ['method' => 'custom', 'amount' => 50000000],
                'purchases' => [['date' => $calendar->today()->format('Y-m-d'), 'amount' => 10000, 'coefficient' => 2.0]],
            ]);
            $this->assertContains('enoughStock', array_column($prepAvisEnStock['candidates'] ?? [], 'key'));
            foreach ($prepAvisEnStock['candidates'] as $candidate) {
                $this->assertSame($expectedEnCurrent, $candidate['planned'][0]['period']['name']);
            }

            // 4. Simulation et commentaire (FR puis EN puis EN en cache)
            $simFr = $this->post("/api/periodes/$currentId/simulation?locale=fr", []);
            $simEn = $this->post("/api/periodes/$currentId/simulation?locale=en", []);
            $this->assertSame([$originalName, $expectedEnCurrent], [$simFr['period']['name'], $simEn['period']['name']]);

            $comFr = $this->post("/api/periodes/$currentId/simulation/commentaire?locale=fr", []);
            $comEn = $this->post("/api/periodes/$currentId/simulation/commentaire?locale=en", []);
            $comEnCached = $this->post("/api/periodes/$currentId/simulation/commentaire?locale=en", []);
            $this->assertSame([$originalName, $expectedEnCurrent, $expectedEnCurrent], [$comFr['simulation']['period']['name'], $comEn['simulation']['period']['name'], $comEnCached['simulation']['period']['name']]);
            $this->assertSame([false, true], [$comEn['cached'], $comEnCached['cached']]);

            // 5. Année
            $yearFr = $this->get('/api/gestion/statistiques/annee?year=2004&locale=fr');
            $yearEn = $this->get('/api/gestion/statistiques/annee?year=2004&locale=en');
            $namesFr = array_column($yearFr['periods'], 'name', 'id');
            $namesEn = array_column($yearEn['periods'], 'name', 'id');
            $this->assertSame('Hiver 2003-2004', $namesFr[$q1Id] ?? null);
            $this->assertSame('Winter 2003-2004', $namesEn[$q1Id] ?? null);

            // 6. Conseils
            $advFr = $this->get("/api/gestion/conseils?periodId=$currentId&locale=fr");
            $advEn = $this->get("/api/gestion/conseils?periodId=$currentId&locale=en");
            $this->assertSame([$originalName, $expectedEnCurrent], [$advFr['period']['name'], $advEn['period']['name']]);

            // La base n'est jamais modifiée par ?locale=en
            $this->assertSame($originalName, $conn->fetchOne('SELECT nom_collection FROM collections WHERE id = ?', [$currentId]));
            $this->assertSame('Hiver 2003-2004', $conn->fetchOne('SELECT nom_collection FROM collections WHERE id = ?', [$q1Id]));

            // 7. Nom modifié manuellement en base (comme par EasyAdmin) : préservé en FR et en EN
            $customName = 'Saison spéciale gérant';
            $conn->executeStatement('UPDATE collections SET nom_collection = ? WHERE id = ?', [$customName, $currentId]);
            $this->em()->clear();
            $this->assertSame($customName, $this->get("/api/periodes/$currentId/budget?locale=fr")['period']['name']);
            $this->assertSame($customName, $this->get("/api/periodes/$currentId/budget?locale=en")['period']['name']);
            $this->assertSame($customName, $this->post("/api/periodes/$currentId/simulation?locale=en", [])['period']['name']);
            $this->assertSame($customName, $this->get("/api/gestion/conseils?periodId=$currentId&locale=en")['period']['name']);
        } finally {
            $conn->executeStatement('UPDATE collections SET nom_collection = ? WHERE id = ?', [$originalName, $currentId]);
            $conn->executeStatement('DELETE FROM gestion_simulation_comment WHERE period_id = ?', [$currentId]);
            if ($createdIds !== []) {
                $placeholders = implode(',', array_fill(0, count($createdIds), '?'));
                $conn->executeStatement("DELETE FROM collections WHERE id IN ($placeholders)", $createdIds);
            }
            $this->endOfWrite();
        }
    }

    private function get(string $route): array
    {
        $this->client->request('GET', $route, [], [], $this->headers());
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 300));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function post(string $route, array $body): array
    {
        $this->client->request('POST', $route, [], [], $this->headers(), json_encode($body));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 300));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function headers(): array
    {
        return ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token];
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
        $email = 'lex-per-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Lex')->setLastname('Periodes')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}

