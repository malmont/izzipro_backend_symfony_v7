<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\Order;
use App\Entity\OrderSource;
use App\Entity\OrderType;
use App\Entity\StatusCommande;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\CaisseService\StoreClock;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot RAP, rapprochement bancaire (11/11/2026, `docs/tresorerie-gestion.md` § 6, contrat validé par l'application) : attendu entre deux soldes
 * (mouvements prévus et dépenses de ]P, D], encaissements de [P, D − 1], part en espèces), écart vivant, décisions, tolérance réglable, dépense
 * oubliée qui réduit l'écart, historique et résumé. Dates de février 2004 : aucune autre donnée datée là dans la base partagée ; tables de
 * trésorerie vidées avant et après.
 */
class ReconciliationApiTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private const TABLES = ['gestion_cash_balance', 'gestion_cash_movement', 'gestion_cash_setting'];

    private KernelBrowser $client;
    private string $token = '';
    private int $userId = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clean();
        $this->login();
    }

    protected function tearDown(): void
    {
        $this->clean();
        $conn = $this->em()->getConnection();
        $conn->executeStatement("DELETE FROM payments WHERE order_payment_id IN (SELECT id FROM \"order\" WHERE reference = 'RAP-TEST')");
        $conn->executeStatement("DELETE FROM \"order\" WHERE reference = 'RAP-TEST'");
        $conn->executeStatement("DELETE FROM note_de_frais WHERE name LIKE 'RAP %'");
        parent::tearDown();
    }

    public function testExpectedGapDecisionsAndForgottenExpense(): void
    {
        $conn = $this->em()->getConnection();
        // Solde d'ouverture : aucun rapprochement
        $opening = $this->send('POST', '/api/gestion/tresorerie/soldes', ['date' => '2004-02-01', 'amount' => 100000], 201);
        $this->assertNull($opening['reconciliation']);
        $this->request('POST', '/api/gestion/tresorerie/soldes/' . $opening['id'] . '/ecart', ['decision' => 'unexplained']);
        $this->assertSame([409, 'NO_PREVIOUS_BALANCE'], [$this->status(), $this->body()['error']]);

        // ]P, D] : loyer 200,00 $ (mensuel, une échéance), remboursement reçu 50,00 $ (une fois), note de frais 30,00 $ ; hors fenêtre : loyer de mars
        $this->send('POST', '/api/gestion/tresorerie/mouvements', ['label' => 'Loyer', 'category' => 'rent', 'amount' => 20000, 'startDate' => '2004-02-05', 'recurrence' => 'monthly'], 201);
        $this->send('POST', '/api/gestion/tresorerie/mouvements', ['label' => 'Remboursement', 'category' => 'other', 'direction' => 'in', 'amount' => 5000, 'startDate' => '2004-02-10', 'recurrence' => 'none'], 201);
        $this->note('2004-02-08', 3000);
        // [P, D − 1] : vente du jour P (60,00 $ carte + 40,00 $ espèces), vente de la veille de D (20,00 $ carte) ; exclues : vente du jour D, annulée
        $this->order('2004-02-01', 3, 10000, [[1, 6000], [2, 4000]]);
        $this->order('2004-02-14', 3, 2000, [[1, 2000]]);
        $this->order('2004-02-15', 3, 7000, [[2, 7000]]);
        $this->order('2004-02-05', 7, 9999, [[2, 9999]]);
        $this->endOfWrite();

        // attendu = 1 000 + 50 − 200 − 30 + 120 = 940,00 $ ; réel 900,00 $ → écart − 40,00 $, à traiter
        $saved = $this->send('POST', '/api/gestion/tresorerie/soldes', ['date' => '2004-02-15', 'amount' => 90000], 201);
        $r = $saved['reconciliation'];
        $this->assertSame([94000, 94000, 90000, -4000, 'open', null], [$r['expected'], $r['expectedAtEntry'], $r['actual'], $r['gap'], $r['status'], $r['decision']]);
        $this->assertSame(['read_receipt', 'add_expense', 'mark_unexplained'], $r['suggestions']);
        $this->assertSame(['from' => '2004-02-02', 'to' => '2004-02-15'], $r['expenseWindow']);
        $c = $r['composition'];
        $this->assertSame([['id' => $opening['id'], 'date' => '2004-02-01', 'amount' => 100000], 5000, 20000, 14], [$c['previous'], $c['plannedIn'], $c['plannedOut'], $c['days']]);
        $this->assertSame(['total' => 3000, 'purchases' => 0, 'shipping' => 0, 'notes' => 3000, 'advertising' => 0], $c['recordedExpenses']);
        $this->assertSame(['total' => 12000, 'cash' => 4000, 'card' => 8000, 'from' => '2004-02-01', 'to' => '2004-02-14'], $c['receipts'], 'encaissements de P à la veille de D, dont espèces');

        // Décisions : validation, écart non justifié (écart vivant), résumé et historique
        $this->request('POST', '/api/gestion/tresorerie/soldes/' . $saved['id'] . '/ecart', ['decision' => 'explained']);
        $this->assertSame([422, ['note' => 'REQUIRED']], [$this->status(), $this->body()['fields']]);
        $this->request('POST', '/api/gestion/tresorerie/soldes/' . $saved['id'] . '/ecart', ['decision' => 'pigeon']);
        $this->assertSame([422, ['decision' => 'INVALID_DECISION']], [$this->status(), $this->body()['fields']]);
        $this->request('POST', '/api/gestion/tresorerie/soldes/999999999/ecart', ['decision' => 'unexplained']);
        $this->assertSame([404, 'BALANCE_NOT_FOUND'], [$this->status(), $this->body()['error']]);
        $marked = $this->send('POST', '/api/gestion/tresorerie/soldes/' . $saved['id'] . '/ecart', ['decision' => 'unexplained', 'note' => '  '], 200);
        $this->assertSame(['unexplained', 'unexplained', null, []], [$marked['status'], $marked['decision']['kind'], $marked['decision']['note'], $marked['suggestions']]);
        $list = $this->get('/api/gestion/tresorerie/rapprochements');
        $this->assertSame(['CAD', 100, 1, ['total' => -4000, 'count' => 1]], [$list['currency'], $list['tolerance'], count($list['items']), $list['unexplained']], 'le solde d\'ouverture n\'a pas de ligne');
        $summary = $this->get('/api/gestion/tresorerie?days=14')['reconciliation'];
        $this->assertSame(['unexplained', -4000, '2004-02-15', ['total' => -4000, 'count' => 1]], [$summary['lastStatus'], $summary['lastGap'], $summary['lastDate'], $summary['unexplained']]);

        // Dépense oubliée saisie plus tard dans la fenêtre : l'écart se réduit seul ; l'attendu à la saisie reste dans l'historique
        $this->note('2004-02-12', 3500);
        $item = $this->get('/api/gestion/tresorerie/rapprochements')['items'][0];
        $this->assertSame([90500, 94000, -500, 'unexplained', ['total' => -500, 'count' => 1]], [$item['expected'], $item['expectedAtEntry'], $item['gap'], $item['status'], $this->get('/api/gestion/tresorerie/rapprochements')['unexplained']]);
        $this->note('2004-02-15', 450); // jour D : compris
        $this->note('2004-02-16', 99999); // après D : hors fenêtre
        $item = $this->get('/api/gestion/tresorerie/rapprochements')['items'][0];
        $this->assertSame([-50, 'matched', 'unexplained', []], [$item['gap'], $item['status'], $item['decision']['kind'], $item['suggestions']], 'dans la tolérance : rapproché, décision gardée');
        $this->assertSame(['total' => 0, 'count' => 0], $this->get('/api/gestion/tresorerie/rapprochements')['unexplained']);
        $this->request('POST', '/api/gestion/tresorerie/soldes/' . $saved['id'] . '/ecart', ['decision' => 'unexplained']);
        $this->assertSame([409, 'GAP_WITHIN_TOLERANCE'], [$this->status(), $this->body()['error']]);

        // Tolérance réglable : 0,10 $ → l'écart de 0,50 $ redevient à traiter... sauf la décision gardée ; « open » efface la décision
        $this->request('PUT', '/api/gestion/tresorerie/reglages', ['tolerance' => -1]);
        $this->assertSame([422, ['tolerance' => 'MUST_BE_INTEGER_CENTS']], [$this->status(), $this->body()['fields']]);
        $this->assertSame(10, $this->send('PUT', '/api/gestion/tresorerie/reglages', ['tolerance' => 10], 200)['tolerance']);
        $this->assertSame('unexplained', $this->get('/api/gestion/tresorerie/rapprochements')['items'][0]['status']);
        $cleared = $this->send('POST', '/api/gestion/tresorerie/soldes/' . $saved['id'] . '/ecart', ['decision' => 'open'], 200);
        $this->assertSame(['open', null], [$cleared['status'], $cleared['decision']]);
        $explained = $this->send('POST', '/api/gestion/tresorerie/soldes/' . $saved['id'] . '/ecart', ['decision' => 'explained', 'note' => 'Dépôt des espèces le 16'], 200);
        $this->assertSame(['explained', 'Dépôt des espèces le 16'], [$explained['status'], $explained['decision']['note']]);
        $this->assertSame(3, (int) $conn->fetchOne("SELECT COUNT(*) FROM content_audit_log WHERE resource = 'gestion-tresorerie-ecarts' AND resource_id = ?", [(string) $saved['id']]), 'journal d\'audit : unexplained, open, explained');

        // Un solde intermédiaire change le précédent du solde suivant : recalcul à la lecture
        $this->send('POST', '/api/gestion/tresorerie/soldes', ['date' => '2004-02-10', 'amount' => 100000], 201);
        $items = $this->get('/api/gestion/tresorerie/rapprochements')['items'];
        $this->assertSame(['2004-02-15', '2004-02-10'], array_column($items, 'date'));
        $this->assertSame('2004-02-10', $items[0]['previousDate']);
    }

    // ----- helpers -----

    private function note(string $day, int $cents): void
    {
        $this->em()->getConnection()->executeStatement("INSERT INTO note_de_frais (id, name, montant, date) VALUES (nextval('note_de_frais_id_seq'), ?, ?, ?)", ['RAP ' . $day, $cents, $day]);
    }

    /** Vente de caisse datée à midi (magasin) de `$day`, statut donné, paiements [[moyen, cents]] réussis */
    private function order(string $day, int $status, int $total, array $payments): void
    {
        $tz = static::getContainer()->get(StoreClock::class)->timezone();
        $at = (new \DateTimeImmutable("$day 12:00", $tz))->setTimezone(new \DateTimeZone('UTC'));
        $order = $this->create(Order::class, ['totalAmount' => (float) $total, 'reference' => 'RAP-TEST'], function (Order $o, EntityManagerInterface $em) use ($at, $status): void {
            $o->setOrderDate(\DateTime::createFromImmutable($at))->setStatus($em->find(StatusCommande::class, $status))->setOrderType($em->find(OrderType::class, 1))
                ->setOrderSource($em->find(OrderSource::class, 2))->setUserId($em->find(User::class, $this->userId));
        });
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE "order" SET gift_card_applied = 0, credit_applied = 0, manufacturer_coupon_applied = 0, cash_rounding = 0 WHERE id = ?', [$order->getId()]);
        foreach ($payments as [$method, $cents]) {
            $conn->executeStatement("INSERT INTO payments (id, order_payment_id, payment_method_id, statut_payment_id, payment_type_id, amount, payment_date) VALUES (nextval('payments_id_seq'), ?, ?, 2, 2, ?, NOW())", [$order->getId(), $method, $cents]);
        }
    }

    private function clean(): void
    {
        $conn = $this->em()->getConnection();
        foreach (self::TABLES as $table) {
            $conn->executeStatement("DELETE FROM $table");
        }
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
        $email = 'gestion-rap-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('Rap')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->userId = (int) $user->getId();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
