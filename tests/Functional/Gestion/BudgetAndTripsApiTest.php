<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\TypeNoteDeFrais;
use App\Entity\TypeFournisseur;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\GestionService\GestionNotificationService;
use App\Services\GestionService\PeriodCalendar;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakePushSender;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot B1a (11/11/2026, `docs/budgets-gestion.md`, contrat validé par l'application) : budget par poste de la période (prévu / réel, postes
 * dans l'ordre, doublons et période fermée refusés, copie, cohérence avec le résultat de période), voyages d'achat (validation,
 * chevauchement, rattachement automatique par la date et à la création du voyage, détachement du gérant jamais défait, périodes fermées
 * en lecture seule, suivi achats / frais, budgetScope), vignettes et notification de dépassement, tripId dans les lectures existantes.
 * Base partagée : les montants se lisent en écarts avant / après ; voyages et prévus vidés au début de chaque test.
 */
class BudgetAndTripsApiTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private KernelBrowser $client;
    private string $token = '';
    private int $userId = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE note_de_frais SET trip_id = NULL, trip_source = NULL WHERE trip_id IS NOT NULL OR trip_source IS NOT NULL');
        $conn->executeStatement('UPDATE commande SET trip_id = NULL, trip_source = NULL WHERE trip_id IS NOT NULL OR trip_source IS NOT NULL');
        foreach (['gestion_trip', 'gestion_budget_line', 'push_device', 'notification_log', 'notification_preference', 'gestion_tile_dismissal'] as $table) { // tables propres à ces tests
            $conn->executeStatement("DELETE FROM $table");
        }
        FakePushSender::reset();
        $this->login();
    }

    public function testPeriodBudgetPlannedVersusActual(): void
    {
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $period = $calendar->current();
        $pid = (int) $period->getId();
        $today = $calendar->today()->format('Y-m-d');
        $b0 = $this->get("/api/periodes/$pid/budget");
        $this->assertSame(['purchases', 'trip', 'shipping', 'advertising', 'other'], array_column($b0['lines'], 'category'));
        $this->assertSame(['Stock', 'Voyage', 'Frais de port', 'Publicité', 'Autres frais'], array_column($b0['lines'], 'label'));
        $this->assertSame([[0, null, 'none']], array_values(array_unique(array_map(fn ($l) => [$l['planned'], $l['ratio'], $l['status']], $b0['lines']), SORT_REGULAR)), 'sans prévu : ratio null, status none');
        $this->assertSame([$period->getBudgetCollection(), 'CAD'], [$b0['globalBudget'], $b0['currency']]);
        $this->assertSame('Trip', $this->get("/api/periodes/$pid/budget?locale=en")['lines'][1]['label']);

        // Prévu : doublon, poste inconnu, montant invalide → 422 sans rien écrire
        $this->request('PUT', "/api/periodes/$pid/budget", ['lines' => [['category' => 'other', 'planned' => 100], ['category' => 'other', 'planned' => 200], ['category' => 'pigeon', 'planned' => 1], ['category' => 'trip', 'planned' => -5]]]);
        $this->assertSame([422, ['lines[1].category' => 'DUPLICATE_CATEGORY', 'lines[2].category' => 'INVALID_CATEGORY', 'lines[3].planned' => 'MUST_BE_INTEGER_CENTS']], [$this->status(), $this->body()['fields']]);
        $this->assertSame(0, $this->get("/api/periodes/$pid/budget")['total']['planned']);
        $b1 = $this->send('PUT', "/api/periodes/$pid/budget", ['lines' => [['category' => 'other', 'planned' => $b0['lines'][4]['actual'] + 10000], ['category' => 'advertising', 'planned' => 1]]], 200);
        $this->assertSame([$b0['lines'][4]['actual'] + 10000, 1], [$b1['lines'][4]['planned'], $b1['lines'][3]['planned']]);
        $this->assertSame($b0['lines'][4]['actual'] + 10001, $b1['total']['planned'], 'total.planned = somme des postes');
        $this->assertSame($period->getBudgetCollection(), $b1['globalBudget'], 'budget global inchangé');
        // Les postes absents gardent leur prévu ; 0 efface
        $b2 = $this->send('PUT', "/api/periodes/$pid/budget", ['lines' => [['category' => 'advertising', 'planned' => 0]]], 200);
        $this->assertSame([$b0['lines'][4]['actual'] + 10000, 0, 'none'], [$b2['lines'][4]['planned'], $b2['lines'][3]['planned'], $b2['lines'][3]['status']]);

        // Réel : une note de frais de 8 000 → other +8000, ratio et status suivent
        $type = $this->noteType();
        $this->send('POST', '/api/notes-de-frais', ['description' => 'Taxi', 'montant' => 8000, 'date' => $today, 'typeNoteDeFraisId' => $type], 201);
        $b3 = $this->get("/api/periodes/$pid/budget");
        $this->assertSame($b2['lines'][4]['actual'] + 8000, $b3['lines'][4]['actual']);
        $this->assertSame('warning', $b3['lines'][4]['status'], 'au moins 80 % du prévu');
        $this->assertNotEmpty(array_filter($b3['lines'][4]['details'], fn ($d) => str_starts_with($d['key'], 'type:')), 'détail par type de note');
        $this->send('POST', '/api/notes-de-frais', ['description' => 'Taxi 2', 'montant' => 2500, 'date' => $today, 'typeNoteDeFraisId' => $type], 201);
        $b4 = $this->get("/api/periodes/$pid/budget");
        $this->assertSame(['over', -500], [$b4['lines'][4]['status'], $b4['lines'][4]['remaining']]);
        // Somme des réels = achats + frais du résultat de période
        $result = $this->get("/api/periodes/$pid/resultat");
        $this->assertSame($result['investment']['purchases'] + $result['expenses']['total'], $b4['total']['actual']);

        // Vignette « budget dépassé » sur la période en cours
        $tiles = $this->get('/api/gestion/accueil/vignettes');
        $tile = $this->tile($tiles, 'budget-over');
        $this->assertNotNull($tile, json_encode(array_column($tiles, 'id')) . json_encode($this->get("/api/periodes/$pid/budget")['lines'][4]));
        $this->assertSame(['alert', 70, 'periode.budget', ['periodId' => $pid], 'other'], [$tile['kind'], $tile['priority'], $tile['action']['screen'], $tile['action']['params'], $tile['figures'][0]['category']]);
        $this->assertSame('1 poste dépasse le prévu.', $tile['text']);

        // Copie : sans source avec un prévu → 404 ; source un an plus tôt (prévu posé en base) → copiée
        $this->request('POST', "/api/periodes/$pid/budget/copier");
        $this->assertSame([404, 'BUDGET_SOURCE_NOT_FOUND'], [$this->status(), $this->body()['error']]);
        $yearBefore = \DateTimeImmutable::createFromInterface($period->getStartDateCollection())->modify('-1 year');
        $calendar->ensurePeriods($yearBefore);
        $source = $calendar->findByDate($yearBefore);
        $this->assertNotNull($source);
        $conn = $this->em()->getConnection();
        $conn->executeStatement("INSERT INTO gestion_budget_line (period_id, category, planned_cents, updated_at) VALUES (?, 'purchases', 300000, NOW()), (?, 'trip', 40000, NOW())", [$source->getId(), $source->getId()]);
        $copied = $this->send('POST', "/api/periodes/$pid/budget/copier", [], 200);
        $this->assertSame([300000, 40000, 0, 0, 0], array_column($copied['lines'], 'planned'), 'tout le prévu est remplacé par celui de la source');
        $this->assertSame([300000, 40000], array_slice(array_column($this->send('POST', "/api/periodes/$pid/budget/copier", ['fromPeriodId' => (int) $source->getId()], 200)['lines'], 'planned'), 0, 2));
        // Période fermée : prévu figé
        $this->assertTrue($calendar->isClosed($source));
        $this->request('PUT', '/api/periodes/' . $source->getId() . '/budget', ['lines' => [['category' => 'other', 'planned' => 1]]]);
        $this->assertSame([409, 'PERIOD_CLOSED'], [$this->status(), $this->body()['error']]);
        $this->assertSame(300000, $this->get('/api/periodes/' . $source->getId() . '/budget')['lines'][0]['planned'], 'lecture d\'une période fermée');
    }

    public function testTripsAutoAttachManualChoiceClosedPeriodsAndFollowUp(): void
    {
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $today = $calendar->today();
        $d = fn (int $days) => $today->modify("$days days")->format('Y-m-d');
        $type = $this->noteType();

        // Validation
        $this->request('POST', '/api/gestion/voyages', ['name' => 'X', 'startDate' => $d(2), 'endDate' => $d(1), 'budgetTotal' => 100, 'currency' => 'EUR', 'budgetScope' => 'tout']);
        $this->assertSame([422, 'BEFORE_START', 'EXCHANGE_RATE_REQUIRED', 'INVALID_SCOPE'], [$this->status(), $this->body()['fields']['endDate'] ?? null, $this->body()['fields']['exchangeRate'] ?? null, $this->body()['fields']['budgetScope'] ?? null]);

        // Base partagée : les notes et commandes d'autres tests aux mêmes dates sont figées « détachées par le gérant » (règle P1)
        $conn = $this->em()->getConnection();
        $conn->executeStatement("UPDATE note_de_frais SET trip_source = 'manual' WHERE trip_id IS NULL AND date BETWEEN ? AND ?", [$d(-15), $d(15)]);
        $conn->executeStatement("UPDATE commande SET trip_source = 'manual' WHERE trip_id IS NULL AND CAST(date AS date) BETWEEN ? AND ?", [$d(-15), $d(15)]);
        // Une note libre datée d'aujourd'hui, une note détachée par le gérant, puis le voyage : la première entre, la seconde jamais
        $free = $this->send('POST', '/api/notes-de-frais', ['description' => 'Hôtel', 'montant' => 12000, 'date' => $d(0), 'typeNoteDeFraisId' => $type], 201);
        $detached = $this->send('POST', '/api/notes-de-frais', ['description' => 'Électricité de la boutique', 'montant' => 9000, 'date' => $d(0), 'typeNoteDeFraisId' => $type], 201);
        $this->assertSame([null, null], [$free['tripId'], $detached['tripId']], 'pas de voyage : rien');
        $this->send('POST', '/api/gestion/voyages/rattacher', ['tripId' => null, 'noteIds' => [$detached['id']]], 200);
        $trip = $this->send('POST', '/api/gestion/voyages', ['name' => 'Salon', 'place' => 'Paris', 'startDate' => $d(-1), 'endDate' => $d(2), 'budgetTotal' => 20000, 'currency' => 'EUR', 'exchangeRate' => 1.5], 201);
        $tid = $trip['id'];
        $this->assertSame([4, 5000, false, 'expenses', 'EUR', 1.5, 30000, 7500], [$trip['days'], $trip['budgetDaily'], $trip['budgetDailyEntered'], $trip['budgetScope'], $trip['currency'], $trip['exchangeRate'], $trip['budgetTotalSite'], $trip['budgetDailySite']], 'journalier = total ÷ jours ; budgets ramenés en devise du site');
        $row = fn (string $table, int $id) => $conn->fetchAssociative("SELECT trip_id, trip_source FROM $table WHERE id = ?", [$id]);
        $this->assertSame(['trip_id' => $tid, 'trip_source' => 'auto'], $row('note_de_frais', $free['id']), 'élément libre rattaché à la création du voyage');
        $this->assertSame(['trip_id' => null, 'trip_source' => 'manual'], $row('note_de_frais', $detached['id']), 'P1 : détaché par le gérant, jamais rattaché de nouveau');
        // Changer les dates du voyage ne le rattache pas non plus
        $this->send('PATCH', "/api/gestion/voyages/$tid", ['startDate' => $d(-2)], 200);
        $this->assertSame(['trip_id' => null, 'trip_source' => 'manual'], $row('note_de_frais', $detached['id']));

        // Chevauchement refusé
        $this->request('POST', '/api/gestion/voyages', ['name' => 'Autre', 'startDate' => $d(2), 'endDate' => $d(5), 'budgetTotal' => 100]);
        $this->assertSame([409, 'TRIP_OVERLAP', $tid], [$this->status(), $this->body()['error'], $this->body()['tripId']]);

        // Note créée pendant le voyage : rattachée seule ; sortie des dates : détachée seule
        $meal = $this->send('POST', '/api/notes-de-frais', ['description' => 'Repas', 'montant' => 3000, 'date' => $d(1), 'typeNoteDeFraisId' => $type], 201);
        $this->assertSame($tid, $meal['tripId'], 'tripId dans la réponse de la note');
        $moved = $this->send('PUT', '/api/notes-de-frais/' . $meal['id'], ['description' => 'Repas', 'montant' => 3000, 'date' => $d(10), 'typeNoteDeFraisId' => $type], 200);
        $this->assertNull($moved['tripId'], 'date sortie du voyage : détachée');
        $back = $this->send('PUT', '/api/notes-de-frais/' . $meal['id'], ['description' => 'Repas', 'montant' => 3000, 'date' => $d(1), 'typeNoteDeFraisId' => $type], 200);
        $this->assertSame($tid, $back['tripId']);
        // Commande fournisseur datée pendant le voyage : rattachée, achats à part dans le suivi
        $supplier = (int) $this->send('POST', '/api/fournisseurs', ['name' => 'Atelier ' . bin2hex(random_bytes(2)), 'typeFournisseur' => ($this->em()->find(TypeFournisseur::class, 1) ?? $this->create(TypeFournisseur::class, ['name' => 'Autre']))->getId()], 201)['id'];
        $commande = $this->send('POST', '/api/commandes', ['budget' => 50000, 'date' => $d(0) . ' 11:00:00', 'name' => 'Achat salon', 'fournisseur' => ['id' => $supplier]], 201);
        $this->assertSame($tid, $commande['tripId'], 'tripId dans la réponse de la commande');
        $slug = 'b1a-' . bin2hex(random_bytes(4));
        $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type) VALUES (nextval('product_id_seq'), 'Robe salon', 5000, false, 0, NOW(), :s, 'standard')", ['s' => $slug]);
        $conn->executeStatement("INSERT INTO commande_line (commande_id, product_id, quantity_ordered, quantity_received, unit_cost_cents, attached_at, source, created_at, updated_at) VALUES (?, (SELECT id FROM product WHERE slug = ?), 4, 0, 2000, NOW(), 'manual', NOW(), NOW())", [$commande['id'], $slug]);

        // Rattachement manuel : un élément inconnu → 422 ; tout ou rien sur une période fermée → 409 avec les identifiants
        $this->request('POST', '/api/gestion/voyages/rattacher', ['tripId' => $tid, 'noteIds' => [999999999]]);
        $this->assertSame([422, ['noteIds[0]' => 'NOTE_NOT_FOUND']], [$this->status(), $this->body()['fields']]);
        $oldDay = $today->modify('-760 days'); // période fermée réservée à ce test (−400 P2, −500 P3, −560 L0, −650 vide)
        $calendar->ensurePeriods($oldDay);
        $old = $calendar->findByDate($oldDay);
        $this->assertTrue($calendar->isClosed($old));
        $conn->executeStatement("INSERT INTO note_de_frais (id, collection_id, type_note_de_frais_id, date, montant, name, description) VALUES (nextval('note_de_frais_id_seq'), ?, ?, ?, 4000, 'Ancienne', 'Ancienne')", [$old->getId(), $type, $oldDay->format('Y-m-d')]);
        $closedNote = (int) $conn->fetchOne('SELECT MAX(id) FROM note_de_frais');
        $outside = $this->send('POST', '/api/notes-de-frais', ['description' => 'Billet d\'avion', 'montant' => 60000, 'date' => $d(-10), 'typeNoteDeFraisId' => $type], 201);
        $this->request('POST', '/api/gestion/voyages/rattacher', ['tripId' => $tid, 'noteIds' => [$outside['id'], $closedNote]]);
        $this->assertSame([409, 'PERIOD_CLOSED', [$closedNote], []], [$this->status(), $this->body()['error'], $this->body()['noteIds'], $this->body()['commandeIds']]);
        $this->assertSame(['trip_id' => null, 'trip_source' => null], $row('note_de_frais', $outside['id']), 'tout ou rien');
        // Un voyage dans une période fermée ignore ses éléments, sans erreur
        $oldTrip = $this->send('POST', '/api/gestion/voyages', ['name' => 'Ancien', 'startDate' => $oldDay->format('Y-m-d'), 'endDate' => $oldDay->format('Y-m-d'), 'budgetTotal' => 100], 201);
        $this->assertSame(['trip_id' => null, 'trip_source' => null], $row('note_de_frais', $closedNote), 'P2 : période fermée en lecture seule');
        $this->request('DELETE', '/api/gestion/voyages/' . $oldTrip['id']);
        $this->assertSame(204, $this->status());
        // Le billet, hors des dates, rattaché à la main : compte dans les totaux et n'est plus déplacé par sa date
        $r = $this->send('POST', '/api/gestion/voyages/rattacher', ['tripId' => $tid, 'noteIds' => [$outside['id']]], 200);
        $this->assertSame([$tid, [['id' => $outside['id'], 'tripId' => $tid]]], [$r['tripId'], $r['notes']]);
        $this->send('PUT', '/api/notes-de-frais/' . $outside['id'], ['description' => 'Billet d\'avion', 'montant' => 60000, 'date' => $d(-11), 'typeNoteDeFraisId' => $type], 200);
        $this->assertSame(['trip_id' => $tid, 'trip_source' => 'manual'], $row('note_de_frais', $outside['id']));

        // Suivi : frais seulement (budgetScope expenses), achats à part ; au-delà du budget à date → over
        $f = $this->get("/api/gestion/voyages/$tid/suivi");
        $this->assertSame(['CAD', 'expenses', true, 8000, 12000 + 3000 + 60000], [$f['currency'], $f['budgetScope'], $f['inProgress'], $f['totals']['purchases'], $f['totals']['expenses']]);
        $this->assertSame($f['totals']['expenses'], $f['totals']['spent'], 'achats non comptés avec expenses');
        $this->assertSame(['purchases' => 8000, 'expenses' => 12000, 'spent' => 12000, 'budget' => 6000], array_intersect_key(array_values(array_filter($f['days'], fn ($x) => $x['date'] === $d(0)))[0], array_flip(['purchases', 'expenses', 'spent', 'budget'])));
        $this->assertSame([5, 3, 30000, 'over'], [count($f['days']), $f['elapsedDays'], $f['totals']['budget'], $f['status']]);
        $this->assertSame([18000, 75000 - 18000], [$f['totals']['allowanceToDate'], $f['overBy']]);
        $all = $this->send('PATCH', "/api/gestion/voyages/$tid", ['budgetScope' => 'all'], 200);
        $this->assertSame('all', $all['budgetScope']);
        $this->assertSame(8000 + 75000, $this->get("/api/gestion/voyages/$tid/suivi")['totals']['spent'], 'all : achats et frais');
        $this->send('PATCH', "/api/gestion/voyages/$tid", ['budgetScope' => 'expenses'], 200);

        // Budget de la période : les notes du voyage passent dans le poste « voyage »
        $pid = (int) $calendar->current()->getId();
        $budget = $this->get("/api/periodes/$pid/budget");
        $this->assertGreaterThanOrEqual(12000, $budget['lines'][1]['actual']);

        // Vignette et notification de dépassement
        $tile = $this->tile($this->get('/api/gestion/accueil/vignettes'), 'trip-budget');
        $this->assertNotNull($tile);
        $this->assertSame(['alert', 75, 'voyages.suivi', ['tripId' => $tid]], [$tile['kind'], $tile['priority'], $tile['action']['screen'], $tile['action']['params']]);
        $this->assertStringStartsWith('Voyage à Paris : 570,00', $tile['text']);
        $this->assertStringEndsWith('au-dessus du budget prévu à ce jour.', $tile['text']);
        $this->send('POST', '/api/notifications/appareils', ['token' => 'b1a-' . bin2hex(random_bytes(3)), 'platform' => 'admin', 'locale' => 'fr'], 201);
        $r = static::getContainer()->get(GestionNotificationService::class)->runDaily(null, true);
        $this->assertSame(1, $r['notified']);
        $this->assertSame(['trip_budget', 'voyages.suivi', 'Budget du voyage dépassé'], [FakePushSender::$sent[0]['data']['kind'], FakePushSender::$sent[0]['data']['screen'], FakePushSender::$sent[0]['title']]);

        // Liste, lecture, 404
        $this->request('GET', '/api/gestion/voyages?from=' . $d(0));
        $this->assertSame([200, '1'], [$this->status(), $this->client->getResponse()->headers->get('X-Total-Count')]);
        $this->request('GET', '/api/gestion/voyages/999999999/suivi');
        $this->assertSame([404, 'TRIP_NOT_FOUND'], [$this->status(), $this->body()['error']]);

        // Suppression : les éléments redeviennent libres
        $this->request('DELETE', "/api/gestion/voyages/$tid");
        $this->assertSame(204, $this->status());
        $this->assertSame(['trip_id' => null, 'trip_source' => null], $row('note_de_frais', $free['id']));
        $this->assertSame(['trip_id' => null, 'trip_source' => null], $row('commande', (int) $commande['id']));
    }

    /** @param list<array<string, mixed>> $tiles */
    private function tile(array $tiles, string $id): ?array
    {
        return array_values(array_filter($tiles, fn ($t) => $t['id'] === $id))[0] ?? null;
    }

    private function noteType(): int
    {
        return (int) ($this->em()->getRepository(TypeNoteDeFrais::class)->findOneBy(['name' => 'Déplacement']) ?? $this->create(TypeNoteDeFrais::class, ['name' => 'Déplacement']))->getId();
    }

    private function status(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    private function body(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function request(string $method, string $route, ?array $body = null): void
    {
        $this->client->request($method, $route, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token], $body === null ? null : json_encode($body));
    }

    private function get(string $route): array
    {
        $this->request('GET', $route);
        $this->assertSame(200, $this->status(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 300));

        return $this->body();
    }

    private function send(string $method, string $route, array $body, int $expected): array
    {
        $this->request($method, $route, $body);
        $this->assertSame($expected, $this->status(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 400));

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
        $email = 'gestion-b1a-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('B1a')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->userId = (int) $user->getId();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
