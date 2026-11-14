<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Gestion\Services\GestionCashService;
use App\Gestion\Services\GestionDayService;
use App\Services\CaisseService\StoreClock;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot AVOIRS (14/11/2026, `docs/statistiques-gestion.md` § 6, `docs/tresorerie-gestion.md`) : un retour ne retire de l'encaissé que
 * l'argent que la caisse a réellement rendu. Ni l'avoir client émis, ni la part revenue sur la carte-cadeau de la vente, ni le coupon du
 * fabricant non rendu ne sortent de l'encaissé ; un retour sans fiche de caisse garde la règle d'avant. Les mêmes chiffres dans la section
 * « Paiements », dans « Ma journée » et dans la trésorerie, au cent. À part, hors de l'encaissé : avoirs clients émis, revenu sur des
 * cartes-cadeaux, payé par avoir client, payé par carte-cadeau ; et à ce jour : avoirs clients en cours, cartes-cadeaux non dépensées.
 *
 * Données du 1er au 10 mai 2007 ; tout est retiré après.
 */
class StoreCreditReceiptsApiTest extends WebTestCase
{
    private const TAG = 'AVOIRS-TEST';
    private const LAST4 = 'AVTS';
    private const POS = 2;
    private const CASH = 2;
    private const CARD = 1;
    private KernelBrowser $client;
    private string $token = '';
    private int $userId = 0;
    private int $register = 0;
    private \Doctrine\DBAL\Connection $db;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->db = $this->em()->getConnection();
        $this->clean();
        $this->login();
        $this->register = (int) $this->db->fetchOne("INSERT INTO cash_register (name, is_active) VALUES ('Poste AVOIRS-TEST', true) RETURNING id");
    }

    protected function tearDown(): void
    {
        $this->db = $this->em()->getConnection();
        $this->clean();
        parent::tearDown();
    }

    public function testAReturnOnlyTakesOutTheMoneyActuallyGivenBackEverywhere(): void
    {
        $before = static::getContainer()->get(GestionCashService::class)->outstandingCredit();
        $ids = $this->sales();
        // Ventes, marge, retours : un retour reste un retour. La réponse sans section est lue avant les fiches de retour et les cartes…
        $s1 = $this->get('/api/gestion/statistiques?from=2007-05-01&to=2007-05-10');
        $this->returnsAndCards($ids);
        // … et après : identique, à la clé près (le lot ne touche ni aux ventes ni à la marge)
        unset($s1['generatedAt']);
        $after = $this->get('/api/gestion/statistiques?from=2007-05-01&to=2007-05-10');
        unset($after['generatedAt']);
        $this->assertSame($s1, $after, 'les statistiques de vente ne bougent pas');

        $p = $this->get('/api/gestion/statistiques?from=2007-05-01&to=2007-05-10&sections=payments')['sections']['payments'];
        // Par jour : reçu, remboursé, net. Avant ce lot : le 2 → net −1000 (carte-cadeau recréditée), le 3 → net 0 (avoir client émis),
        // le 4 → net −200 (coupon du fabricant), le 6 → net −1200 (avoir client émis)
        $days = [];
        foreach ($p['chart']['points'] as $point) {
            $days[$point['key']] = [$point['received'], $point['refunded'], $point['net']];
        }
        $expected = [
            '2007-05-01' => [5000, 0, 5000],     // vente d'une carte-cadeau : de l'argent reçu
            '2007-05-02' => [2335, 2335, 0],     // vente 23,35 espèces + 10,00 carte-cadeau, rendue : 23,35 en espèces, 10,00 sur la carte
            '2007-05-03' => [3334, 0, 3334],     // vente en espèces, retour rendu en avoir client ; vente payée par cet avoir : rien de reçu
            '2007-05-04' => [3135, 3135, 0],     // vente 31,35 espèces + 2,00 coupon, rendue : 31,35 en espèces, le coupon n'est pas rendu
            '2007-05-05' => [5000, 2500, 2500],  // échange (rien ne sort), retour en espèces (15,00), retour sans fiche de caisse (10,00)
            '2007-05-06' => [0, 0, 0],           // retour rendu en avoir client
            '2007-05-07' => [0, 800, -800],      // retour rendu par carte, avec sa fiche de caisse
            '2007-05-08' => [0, 600, -600],      // retour sans fiche de caisse : la règle d'avant (10,00 moins 4,00 reportés)
        ];
        $this->assertSame($expected, array_intersect_key($days, $expected));
        $this->assertSame([[0, 0, 0], [0, 0, 0]], [$days['2007-05-09'], $days['2007-05-10']]);
        $this->assertSame([18804, 9370, 9434], [$p['indicators']['received']['value'], $p['indicators']['refunded']['value'], $p['indicators']['net']['value']]);

        // Les moyens de paiement retrouvent l'encaissé ; seul le retour sans fiche ni ligne de paiement reste en « autre »
        $methods = [];
        foreach ($p['breakdown'] as $line) {
            $methods[$line['method']] = [$line['received'], $line['refunded'], $line['net']];
        }
        $this->assertSame(['card' => [5000, 1800, 3200], 'cash' => [13804, 6970, 6834], 'other' => [null, null, -600]], $methods);
        $this->assertSame([['REFUNDS_UNMATCHED', -600]], array_values(array_map(fn (array $c) => [$c['code'], $c['amount']], array_filter($p['caveats'], fn (array $c) => isset($c['amount'])))));
        // Sans ce retour (du 1er au 7) : tout se recoupe, plus de ligne « autre » ni de remarque d'écart
        $week = $this->get('/api/gestion/statistiques?from=2007-05-01&to=2007-05-07&sections=payments')['sections']['payments'];
        $this->assertSame(10034, $week['indicators']['net']['value']);
        $this->assertSame(10034, array_sum(array_column($week['breakdown'], 'net')));
        $this->assertSame(['card', 'cash'], array_column($week['breakdown'], 'method'));
        $this->assertSame(['PAYMENTS_BY_ORDER_DATE'], array_column($week['caveats'], 'code'));

        // À part, hors de l'encaissé
        // (deux avoirs émis par un retour, 33,34 et 12,00, et un avoir recrédité de 5,00)
        $this->assertSame(['amount' => 5034, 'count' => 3, 'label' => 'Avoirs clients émis'], $p['storeCreditIssued']);
        $this->assertSame(['amount' => 1000, 'count' => 1, 'label' => 'Revenu sur des cartes-cadeaux'], $p['giftCardRecredited']);
        $this->assertSame(['amount' => 3334, 'count' => 1, 'label' => 'Payé par avoir client'], $p['paidByStoreCredit']);
        $this->assertSame(['amount' => 1000, 'count' => 1, 'label' => 'Payé par carte-cadeau'], $p['paidByGiftCard']);
        // À ce jour : l'avoir client encore actif (12,00) et la carte-cadeau non dépensée (50,00) ; ni la carte expirée, ni l'annulée, ni
        // l'avoir épuisé. Comparé à l'état d'avant le jeu d'essai (d'autres tests du même lancement laissent des cartes)
        $this->assertSame([$before['storeCredit']['amount'] + 1200, $before['storeCredit']['count'] + 1, 'Avoirs clients en cours, à ce jour'],
            [$p['outstandingStoreCredit']['amount'], $p['outstandingStoreCredit']['count'], $p['outstandingStoreCredit']['label']]);
        $this->assertSame([$before['giftCards']['amount'] + 5000, $before['giftCards']['count'] + 1, 'Cartes-cadeaux non dépensées, à ce jour'],
            [$p['outstandingGiftCards']['amount'], $p['outstandingGiftCards']['count'], $p['outstandingGiftCards']['label']]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $p['outstandingStoreCredit']['asOf']);

        // Anglais ; la caisse seule produit ces lignes : null pour la boutique en ligne, comme les clôtures de caisse
        $en = $this->get('/api/gestion/statistiques?from=2007-05-01&to=2007-05-10&sections=payments&locale=en&source=pos')['sections']['payments'];
        $this->assertSame(['Store credit issued', 'Returned to gift cards', 'Paid by store credit', 'Paid by gift card', 'Outstanding store credit, to date', 'Unspent gift cards, to date'],
            [$en['storeCreditIssued']['label'], $en['giftCardRecredited']['label'], $en['paidByStoreCredit']['label'], $en['paidByGiftCard']['label'], $en['outstandingStoreCredit']['label'], $en['outstandingGiftCards']['label']]);
        $this->assertSame(5034, $en['storeCreditIssued']['amount']);
        $web = $this->get('/api/gestion/statistiques?from=2007-05-01&to=2007-05-10&sections=payments&source=ecommerce')['sections']['payments'];
        $this->assertSame([null, null, null, null, null, null, null],
            [$web['storeCreditIssued'], $web['giftCardRecredited'], $web['paidByStoreCredit'], $web['paidByGiftCard'], $web['outstandingStoreCredit'], $web['outstandingGiftCards'], $web['cashDesk']]);

        // Trésorerie : les mêmes jours, au cent ; part en espèces = les lignes de paiement en espèces
        $this->em();
        $cash = static::getContainer()->get(GestionCashService::class);
        $byDay = $cash->receiptsByDay('2007-05-01', '2007-05-10')['days'];
        ksort($byDay);
        $this->assertSame(['2007-05-01' => 5000, '2007-05-02' => 0, '2007-05-03' => 3334, '2007-05-04' => 0, '2007-05-05' => 2500, '2007-05-06' => 0, '2007-05-07' => -800, '2007-05-08' => -600], $byDay);
        $this->assertSame(['total' => 9434, 'cash' => 6834, 'card' => 2600], $cash->receiptsSplit('2007-05-01', '2007-05-10'));
        $this->assertSame(['amount' => 0, 'count' => 0], $cash->nonCashFlows('2007-05-10', '2007-05-01')['storeCreditIssued'], 'fenêtre vide');

        // « Ma journée » du 3 mai 2007 : encaissé 33,34 (le retour en avoir client n'en sort pas), et les lignes à part du jour
        $tz = static::getContainer()->get(StoreClock::class)->timezone();
        $day = static::getContainer()->get(GestionDayService::class)->overview(new \DateTimeImmutable('2007-05-03 18:00:00', $tz))['receipts'];
        $this->assertSame(3334, $day['total']);
        $this->assertSame([['method' => 'cash', 'total' => 3334], ['method' => 'card', 'total' => 0]], $day['byMethod']);
        $this->assertSame([['amount' => 3334, 'count' => 1], ['amount' => 0, 'count' => 0], ['amount' => 3334, 'count' => 1], ['amount' => 0, 'count' => 0]],
            [$day['storeCreditIssued'], $day['giftCardRecredited'], $day['paidByStoreCredit'], $day['paidByGiftCard']]);
        $this->assertSame($before['storeCredit']['amount'] + 1200, $day['outstandingStoreCredit']['amount']);
        $this->assertSame($before['giftCards']['amount'] + 5000, $day['outstandingGiftCards']['amount']);
        // … et celle du 2 mai : les lignes sont celles du jour seulement (rien du 3), l'encaissé du jour est nul
        $eve = static::getContainer()->get(GestionDayService::class)->overview(new \DateTimeImmutable('2007-05-02 18:00:00', $tz))['receipts'];
        $this->assertSame([0, ['amount' => 0, 'count' => 0], ['amount' => 1000, 'count' => 1], ['amount' => 0, 'count' => 0], ['amount' => 1000, 'count' => 1]],
            [$eve['total'], $eve['storeCreditIssued'], $eve['giftCardRecredited'], $eve['paidByStoreCredit'], $eve['paidByGiftCard']]);
    }

    /** @return array<string, int> les commandes du jeu d'essai, par nom */
    private function sales(): array
    {
        $o = [];
        $o['giftCardSale'] = $this->order('2007-05-01', 3, 5000, [[self::CASH, 5000]]);
        $o['saleA'] = $this->order('2007-05-02', 1, 3334, [[self::CASH, 2335]], ['cash_rounding' => 1, 'gift_card_applied' => 1000]);
        $o['returnA'] = $this->order('2007-05-02', 2, -3334, [[self::CASH, -2335]], ['cash_rounding' => -1], '15:00');
        $o['saleB'] = $this->order('2007-05-03', 1, 3334, [[self::CASH, 3334]]);
        $o['returnB'] = $this->order('2007-05-03', 2, -3334, [], [], '14:00');
        $o['saleC'] = $this->order('2007-05-03', 1, 3334, [], ['gift_card_applied' => 3334], '16:00');
        $o['saleD'] = $this->order('2007-05-04', 1, 3334, [[self::CASH, 3135]], ['cash_rounding' => 1, 'manufacturer_coupon_applied' => 200]);
        $o['returnD'] = $this->order('2007-05-04', 2, -3334, [[self::CASH, -3135]], ['cash_rounding' => -1], '15:00');
        $o['saleE'] = $this->order('2007-05-05', 1, 5000, [[self::CARD, 5000]]);
        $o['exchange'] = $this->order('2007-05-05', 2, -2000, [], ['credit_applied' => 2000], '13:00');
        $o['returnCash'] = $this->order('2007-05-05', 2, -1500, [[self::CASH, -1500]], [], '14:00');
        $o['returnNoRecord'] = $this->order('2007-05-05', 2, -1000, [[self::CARD, -1000]], [], '15:00');
        $o['returnI'] = $this->order('2007-05-06', 2, -1200, []);
        $o['returnCard'] = $this->order('2007-05-07', 2, -800, [[self::CARD, -800]]);
        $o['returnNoRecordCredit'] = $this->order('2007-05-08', 2, -1000, [], ['credit_applied' => 400]);

        return $o;
    }

    /** Les fiches de retour de la caisse, les cartes-cadeaux et les avoirs clients, avec leurs mouvements */
    private function returnsAndCards(array $o): void
    {
        $giftCard = $this->card('gift_card', 5000, 5000, 'active', $o['giftCardSale']);
        $this->move($giftCard, 'issue', 5000, '2007-05-01', '12:00', $o['giftCardSale']);
        $this->move($giftCard, 'redeem', -1000, '2007-05-02', '12:00', $o['saleA']);
        $returnA = $this->posReturn($o['saleA'], $o['returnA'], 'original', 3334, ['cash_refund' => 2335, 'cash_rounding' => 1, 'gift_card_refund' => 1000]);
        $this->move($giftCard, 'refund', 1000, '2007-05-02', '15:00', $o['saleA'], $returnA);

        $used = $this->card('store_credit', 3334, 0, 'depleted');
        $returnB = $this->posReturn($o['saleB'], $o['returnB'], 'store_credit', 3334, ['store_credit_id' => $used]);
        $this->move($used, 'issue', 3334, '2007-05-03', '14:00', null, $returnB);
        $this->move($used, 'redeem', -3334, '2007-05-03', '16:00', $o['saleC']);

        $this->posReturn($o['saleD'], $o['returnD'], 'cash', 3334, ['cash_refund' => 3135, 'cash_rounding' => 1]);
        $this->posReturn($o['saleE'], $o['exchange'], 'original', 2000, ['credit_applied' => 2000]);
        $this->posReturn($o['saleE'], $o['returnCash'], 'cash', 1500, ['cash_refund' => 1500]);

        $open = $this->card('store_credit', 1200, 1200, 'active');
        $returnI = $this->posReturn($o['saleE'], $o['returnI'], 'store_credit', 1200, ['store_credit_id' => $open]);
        $this->move($open, 'issue', 1200, '2007-05-06', '12:00', null, $returnI);
        $this->move($open, 'refund', 500, '2007-05-06', '13:00');
        $this->posReturn($o['saleE'], $o['returnCard'], 'card', 800, ['card_refund' => 800]);

        // Ne comptent pas « à ce jour » : une carte-cadeau expirée, une carte annulée (même avec un solde), un avoir actif à zéro
        $this->card('gift_card', 700, 700, 'active', null, '2008-01-01 00:00:00');
        $this->card('gift_card', 2500, 300, 'canceled');
        $this->card('store_credit', 400, 0, 'active');
        // Émise hors de la plage : ne compte pas dans les lignes de la plage
        $late = $this->card('store_credit', 900, 0, 'depleted');
        $this->move($late, 'issue', 900, '2007-05-20', '12:00');
        $this->move($late, 'redeem', -900, '2007-05-21', '12:00');
    }

    private function order(string $day, int $type, int $total, array $payments, array $extra = [], string $time = '12:00'): int
    {
        $columns = ['reference' => self::TAG, 'order_date' => $this->utc($day, $time), 'order_type_id' => $type, 'status_id' => 3, 'order_source_id' => self::POS, 'user_id_id' => $this->userId, 'total_amount' => $total] + $extra;
        $id = (int) $this->db->fetchOne(sprintf('INSERT INTO "order" (id, %s) VALUES (nextval(\'order_id_seq\'), %s) RETURNING id', implode(', ', array_keys($columns)), implode(', ', array_fill(0, count($columns), '?'))), array_values($columns));
        foreach ($payments as [$method, $amount]) {
            $this->db->executeStatement("INSERT INTO payments (id, order_payment_id, payment_method_id, statut_payment_id, payment_type_id, amount, payment_date) VALUES (nextval('payments_id_seq'), ?, ?, 2, ?, ?, NOW())",
                [$id, $method, $amount < 0 ? 1 : 2, $amount]);
        }

        return $id;
    }

    private function posReturn(int $sale, int $returnOrder, string $method, int $amount, array $extra = []): int
    {
        $columns = ['order_id' => $sale, 'return_order_id' => $returnOrder, 'cash_register_id' => $this->register, 'user_id' => $this->userId, 'refund_method' => $method, 'amount' => $amount,
            'created_at' => (string) $this->db->fetchOne('SELECT order_date FROM "order" WHERE id = ?', [$returnOrder])] + $extra;

        return (int) $this->db->fetchOne(sprintf('INSERT INTO pos_return (%s) VALUES (%s) RETURNING id', implode(', ', array_keys($columns)), implode(', ', array_fill(0, count($columns), '?'))), array_values($columns));
    }

    private function card(string $kind, int $initial, int $balance, string $status, ?int $issuedOrder = null, ?string $expiresAt = null): int
    {
        return (int) $this->db->fetchOne('INSERT INTO pos_gift_card (kind, code_hash, last4, initial_amount, balance, status, expires_at, issued_order_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [$kind, hash('sha256', self::TAG . bin2hex(random_bytes(8))), self::LAST4, $initial, $balance, $status, $expiresAt, $issuedOrder, '2007-05-01 12:00:00']);
    }

    private function move(int $card, string $kind, int $amount, string $day, string $time, ?int $order = null, ?int $return = null): void
    {
        $this->db->executeStatement('INSERT INTO pos_gift_card_transaction (gift_card_id, kind, amount, balance_after, order_id, return_id, created_at) VALUES (?, ?, ?, 0, ?, ?, ?)',
            [$card, $kind, $amount, $order, $return, $this->utc($day, $time)]);
    }

    private function utc(string $day, string $time): string
    {
        return (new \DateTimeImmutable("$day $time", static::getContainer()->get(StoreClock::class)->timezone()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function clean(): void
    {
        $this->db->executeStatement('DELETE FROM pos_gift_card_transaction WHERE gift_card_id IN (SELECT id FROM pos_gift_card WHERE last4 = ?)', [self::LAST4]);
        $this->db->executeStatement('DELETE FROM pos_return WHERE return_order_id IN (SELECT id FROM "order" WHERE reference = ?)', [self::TAG]);
        $this->db->executeStatement('DELETE FROM pos_gift_card WHERE last4 = ?', [self::LAST4]);
        $this->db->executeStatement('DELETE FROM payments WHERE order_payment_id IN (SELECT id FROM "order" WHERE reference = ?)', [self::TAG]);
        $this->db->executeStatement('DELETE FROM "order" WHERE reference = ?', [self::TAG]);
        $this->db->executeStatement("DELETE FROM cash_register WHERE name = 'Poste AVOIRS-TEST'");
    }

    private function get(string $route): array
    {
        $this->client->request('GET', $route, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 400));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function em(): EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }

    private function login(): void
    {
        $email = 'gestion-avoirs-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('Avoirs')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->userId = (int) $user->getId();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        static::getContainer()->get(CacheInvalidationSubscriber::class)->invalidateWrittenTenants();
    }
}
