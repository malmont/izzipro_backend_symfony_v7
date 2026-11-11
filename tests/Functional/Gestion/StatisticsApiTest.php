<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\Carrier;
use App\Entity\Collections;
use App\Entity\Commande;
use App\Entity\FraisDePort;
use App\Entity\NoteDeFrais;
use App\Entity\Order;
use App\Entity\OrderSource;
use App\Entity\OrderTax;
use App\Entity\OrderType;
use App\Entity\StatusCommande;
use App\Entity\Tax;
use App\Entity\Transporteur;
use App\Entity\TypeFournisseur;
use App\Entity\TypeNoteDeFrais;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\CaisseService\StoreClock;
use App\Services\StatistiqueService\SalesStatisticsService;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Statistiques de l'application de gestion (lot G3, 09/11/2026, `docs/gestion-mise-a-niveau.md`) : chiffre d'affaires,
 * nombre de commandes, panier moyen, transporteurs, taxes, frais, au fuseau du magasin, montants en cents, mois en numéro,
 * `currency` à la racine ; référentiels traduits selon `?locale=`. La base de test est partagée avec d'autres tests qui
 * créent des commandes : les assertions portent sur des écarts avant / après.
 */
class StatisticsApiTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private KernelBrowser $client;
    private int $userId = 0;
    private string $token = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->login(['ROLE_ADMIN']);
    }

    public function testSalesStatisticsFollowStoreDayAndCents(): void
    {
        $today = $this->storeNow();
        $before = $this->get('/api/statistiques/chiffre-affaires/pos');
        $beforeAll = $this->get('/api/statistiques/chiffre-affaires');
        $countBefore = $this->get('/api/statistiques/nombre-commandes/pos');
        $carrierBefore = $this->get('/api/carrier/statistics');
        $taxBefore = $this->get('/api/taxes/monthly');

        $carrier = $this->create(Carrier::class, ['price' => 1500.0]);
        $this->order(1, 3, 2, 1000.0, $today);
        $this->order(1, 3, 2, 2500.0, $today, $carrier);
        $this->order(2, 6, 2, 400.0, $today);           // retour : compté dans le chiffre d'affaires
        $this->order(3, 3, 2, 9999.0, $today);          // carte-cadeau : passif, exclu
        $this->order(1, 3, 1, 700.0, $today);           // boutique web : hors « pos »
        $this->order(1, 3, 2, 5000.0, $today->modify('-1 year')); // l'an dernier
        $tax = $this->em()->getRepository(Tax::class)->findOneBy([]) ?? $this->create(Tax::class); // référentiel des taxes : identifiants posés par script, la séquence peut être en retard
        $taxed = $this->order(1, 3, 2, 1150.0, $today);
        $this->create(OrderTax::class, ['amount' => 150.0, 'base' => 1000], fn (OrderTax $t, EntityManagerInterface $em) => $t->setOrderTax($em->find(Order::class, $taxed->getId()))->setTax($em->find(Tax::class, $tax->getId())));
        $this->endOfWrite();

        $after = $this->get('/api/statistiques/chiffre-affaires/pos');
        $this->assertSame('CAD', $after['currency']);
        $this->assertSame(5050, $after['currentWeekRevenue'] - $before['currentWeekRevenue'], 'ventes et retours de la caisse de la semaine, en cents (1000 + 2500 + 400 + 1150)');
        $this->assertSame(5050, $after['currentMonthRevenue'] - $before['currentMonthRevenue']);
        $this->assertSame(5050, $after['currentYearRevenue'] - $before['currentYearRevenue']);
        $this->assertSame(5000, $after['lastYearRevenue'] - $before['lastYearRevenue']);
        $this->assertSame(5750, $this->get('/api/statistiques/chiffre-affaires')['currentWeekRevenue'] - $beforeAll['currentWeekRevenue'], 'toutes sources : + la boutique web');
        $daily = $after['dailyRevenueForCurrentWeek'];
        $this->assertCount(7, $daily);
        $this->assertSame($today->modify('monday this week')->format('Y-m-d'), $daily[0]['date'], 'semaine du magasin, du lundi');
        $byDay = array_column($daily, 'revenue', 'date');
        $beforeByDay = array_column($before['dailyRevenueForCurrentWeek'], 'revenue', 'date');
        $this->assertSame(5050, $byDay[$today->format('Y-m-d')] - ($beforeByDay[$today->format('Y-m-d')] ?? 0), 'journée du magasin');
        $monthly = $after['monthlyRevenueForCurrentYear'];
        $this->assertSame(range(1, (int) $today->format('n')), array_column($monthly, 'month'), 'mois en numéro, jusqu\'au mois courant');
        $this->assertIsInt($monthly[0]['revenue']);
        $weekly = $after['weeklyRevenueForCurrentMonth'];
        $this->assertNotEmpty($weekly);
        $this->assertIsInt($weekly[0]['week']);

        // Intervalle libre (jours du magasin)
        $interval = $this->get('/api/statistiques/chiffre-affaires/pos?startDate=' . $today->format('Y-m-d') . '&endDate=' . $today->format('Y-m-d'));
        $this->assertSame([$today->format('Y-m-d'), 'CAD'], [$interval['startDate'], $interval['currency']]);
        $this->assertIsInt($interval['revenue']);
        $this->assertGreaterThanOrEqual(5050, $interval['revenue']);
        $this->request('GET', '/api/statistiques/chiffre-affaires/pos?startDate=pas-une-date&endDate=2026-01-01');
        $this->assertSame(400, $this->client->getResponse()->getStatusCode());

        // Nombre de commandes : clés historiques (accents compris), jours en date ISO
        $count = $this->get('/api/statistiques/nombre-commandes/pos');
        $this->assertSame(3, $count['currentWeekCountAchatClientComplétée'] - $countBefore['currentWeekCountAchatClientComplétée'], 'achats complétés de la caisse cette semaine (1000, 2500, 1150)');
        $this->assertSame(1, $count['currentWeekCountRetourClientLivrée'] - $countBefore['currentWeekCountRetourClientLivrée']);
        $this->assertCount(7, $count['dailyCountAchatClient_ComplétéeForCurrentWeek']);
        $this->assertSame(['date', 'orderCount'], array_keys($count['dailyCountAchatClient_ComplétéeForCurrentWeek'][0]));
        $this->assertSame(4, $count['currentMonthCount'] - $countBefore['currentMonthCount'], 'ventes et retours de la caisse du mois');
        $this->assertSame(1, $count['lastYearCount'] - $countBefore['lastYearCount']);
        $this->assertSame(range(1, (int) $today->format('n')), array_column($count['monthlyCountForCurrentYear'], 'month'));
        $this->assertSame('CAD', $count['currency']);

        // Panier moyen : cents entiers
        $average = $this->get('/api/statistiques/panier-moyen/pos');
        foreach (['currentWeekAverage', 'lastWeekAverage', 'currentMonthAverage', 'lastAverageCount', 'currentYearAverage'] as $key) {
            $this->assertIsInt($average[$key], $key);
        }
        $this->assertCount(7, $average['dailyAverageForCurrentWeek']);
        $this->assertSame('CAD', $average['currency']);

        // Transporteurs : montant par mois (clé historique carrier_count + shippingAmount)
        $carrierAfter = $this->get('/api/carrier/statistics');
        $m = (int) $today->format('n') - 1;
        $this->assertSame(range(1, 12), array_column($carrierAfter['monthly_carrier_for_current_year'], 'month'));
        $this->assertSame(1500, $carrierAfter['monthly_carrier_for_current_year'][$m]['shippingAmount'] - $carrierBefore['monthly_carrier_for_current_year'][$m]['carrier_count']);
        $this->assertSame($carrierAfter['monthly_carrier_for_current_year'][$m]['carrier_count'], $carrierAfter['monthly_carrier_for_current_year'][$m]['shippingAmount']);
        $this->assertSame('CAD', $carrierAfter['currency']);

        // Taxes par mois
        $taxAfter = $this->get('/api/taxes/monthly');
        $this->assertSame(150, $taxAfter['monthly_taxes_for_current_year'][$m]['total_tax'] - $taxBefore['monthly_taxes_for_current_year'][$m]['total_tax']);
        $this->assertSame($m + 1, $taxAfter['monthly_taxes_for_current_year'][$m]['month']);
    }

    /**
     * Correctifs B3 à B5 (11/11/2026) : panier moyen sans incomplètes ni annulées + lastYearAverage ; nombre de ventes (type 1, mêmes statuts)
     * en clés additives, anciennes clés inchangées ; frais de port réellement facturés et ventes avec transporteur par mois. Source mobile_app (3).
     */
    public function testSalesFixesKeepOnlyRealSales(): void
    {
        $today = $this->storeNow();
        $lastYear = $today->modify('-1 year');
        $count0 = $this->get('/api/statistiques/nombre-commandes/mobile_app');
        $carrier0 = $this->get('/api/carrier/statistics');
        $p = static::getContainer()->get(SalesStatisticsService::class)->periods();
        $base = fn (string $period) => $this->kept(3, $p[$period]);
        [$yearN, $yearSum] = $base('currentYear');
        [$lastN, $lastSum] = $base('lastYear');

        $carrier = $this->create(Carrier::class, ['price' => 1500.0]);
        $this->order(1, 3, 3, 1000.0, $today, $carrier, 500.0);   // vente avec transporteur, 5,00 $ de port facturés
        $this->order(1, 1, 3, 9000.0, $today, $carrier, 700.0);   // incomplète : exclue
        $this->order(1, 7, 3, 8000.0, $today, null, 300.0);       // annulée : exclue
        $this->order(2, 6, 3, -400.0, $today);                    // retour
        $this->order(1, 3, 3, 3000.0, $today, null, 0.0);         // vente sans transporteur
        $this->order(1, 3, 3, 2600.0, $lastYear);
        $this->order(1, 7, 3, 6000.0, $lastYear);
        $this->endOfWrite();

        $count = $this->get('/api/statistiques/nombre-commandes/mobile_app');
        $delta = fn (string $k) => $count[$k] - $count0[$k];
        $this->assertSame([2, 2, 1], [$delta('currentMonthSalesCount'), $delta('currentYearSalesCount'), $delta('lastYearSalesCount')], 'ventes (type 1) hors incomplètes et annulées');
        $this->assertSame(0, $delta('lastMonthSalesCount'));
        $this->assertSame(5, $delta('currentMonthCount'), 'ancienne clé inchangée : ventes et retours, tous statuts');
        $this->assertSame(2, array_sum(array_column($count['weeklySalesCountForCurrentMonth'], 'orderCount')) - array_sum(array_column($count0['weeklySalesCountForCurrentMonth'], 'orderCount')));
        $m = (int) $today->format('n') - 1;
        $this->assertSame(['month' => $m + 1, 'orderCount' => $count0['monthlySalesCountForCurrentYear'][$m]['orderCount'] + 2], $count['monthlySalesCountForCurrentYear'][$m]);

        $average = $this->get('/api/statistiques/panier-moyen/mobile_app');
        $this->assertSame((int) round(($yearSum + 4000) / ($yearN + 2)), $average['currentYearAverage'], 'panier moyen de l\'année : 1000 et 3000 seulement');
        $this->assertSame((int) round(($lastSum + 2600) / ($lastN + 1)), $average['lastYearAverage'], 'an dernier : l\'annulée exclue');
        $this->assertIsInt($average['lastYearAverage']);

        $carrierAfter = $this->get('/api/carrier/statistics')['monthly_carrier_for_current_year'][$m];
        $before = $carrier0['monthly_carrier_for_current_year'][$m];
        $this->assertSame([500, 1, 3000], [$carrierAfter['shippingBilled'] - $before['shippingBilled'], $carrierAfter['orders'] - $before['orders'], $carrierAfter['carrier_count'] - $before['carrier_count']],
            'port facturé et ventes avec transporteur (hors incomplètes et annulées) ; ancien montant catalogue inchangé (2 × 15,00 $)');
    }

    public function testPurchaseExpensesTotals(): void
    {
        $today = $this->storeNow();
        $before = $this->get('/api/frais/total');

        $collection = $this->create(Collections::class, ['del' => false]);
        $commande = $this->create(Commande::class, [], fn (Commande $c, EntityManagerInterface $em) => $c->setDate(\DateTime::createFromInterface($today))->setCollections($em->find(Collections::class, $collection->getId())));
        $transporteur = $this->create(Transporteur::class);
        $this->create(FraisDePort::class, ['price' => 2500], fn (FraisDePort $f, EntityManagerInterface $em) => $f->setCommande($em->find(Commande::class, $commande->getId()))->setTransporteur($em->find(Transporteur::class, $transporteur->getId())));
        $type = $this->create(TypeNoteDeFrais::class, ['name' => 'Repas']);
        $this->create(NoteDeFrais::class, ['montant' => 800], fn (NoteDeFrais $n, EntityManagerInterface $em) => $n->setDate(\DateTime::createFromInterface($today))->setCollection($em->find(Collections::class, $collection->getId()))->setTypeNoteDeFrais($em->find(TypeNoteDeFrais::class, $type->getId())));
        $this->endOfWrite();

        $after = $this->get('/api/frais/total');
        $this->assertSame((int) $today->format('Y'), $after['total_frais_annee']['year']);
        $this->assertSame([3300, 800, 2500], [
            $after['total_frais_annee']['total_frais']['total'] - $before['total_frais_annee']['total_frais']['total'],
            $after['total_frais_annee']['total_frais']['note_de_frais'] - $before['total_frais_annee']['total_frais']['note_de_frais'],
            $after['total_frais_annee']['total_frais']['frais_de_port'] - $before['total_frais_annee']['total_frais']['frais_de_port'],
        ], 'année : notes de frais + frais de port (datés par la commande), cents');
        $this->assertSame(range(1, 12), array_column($after['frais_par_mois'], 'month'));
        $m = (int) $today->format('n') - 1;
        $this->assertSame(3300, $after['frais_par_mois'][$m]['total_frais']['total'] - $before['frais_par_mois'][$m]['total_frais']['total']);
        $this->assertCount(7, $after['frais_par_jour']);
        $byDay = array_column(array_map(fn ($d) => ['date' => $d['date'], 'total' => $d['total_frais']['total']], $after['frais_par_jour']), 'total', 'date');
        $beforeByDay = array_column(array_map(fn ($d) => ['date' => $d['date'], 'total' => $d['total_frais']['total']], $before['frais_par_jour']), 'total', 'date');
        $this->assertSame(3300, $byDay[$today->format('Y-m-d')] - ($beforeByDay[$today->format('Y-m-d')] ?? 0));
        $this->assertSame('CAD', $after['currency']);
    }

    public function testReferentialsAreTranslated(): void
    {
        $this->create(TypeFournisseur::class, ['name' => 'Grossiste']);
        $this->create(TypeFournisseur::class, ['name' => 'Importateur maison']);
        $this->endOfWrite();
        $fr = array_column($this->get('/api/type-fournisseurs?locale=fr'), 'name');
        $en = array_column($this->get('/api/type-fournisseurs?locale=en'), 'name');
        $this->assertContains('Grossiste', $fr);
        $this->assertContains('Wholesaler', $en, 'nom traduit (translations/gestion.en.yaml)');
        $this->assertNotContains('Grossiste', $en);
        $this->assertContains('Importateur maison', $en, 'nom sans traduction : tel quel');

        $this->create(TypeNoteDeFrais::class, ['name' => 'Hébergement']);
        $this->endOfWrite();
        $this->assertContains('Lodging', array_column($this->get('/api/type-note-de-frais?locale=en'), 'name'));
    }

    // ----- helpers -----

    /** @return array{0: int, 1: float} commandes gardées (ventes et retours > 0, hors incomplètes et annulées) d'une source sur [début, fin[ */
    private function kept(int $source, array $period): array
    {
        $row = $this->em()->getConnection()->fetchAssociative('SELECT COUNT(*) AS n, COALESCE(SUM(total_amount), 0) AS s FROM "order" WHERE order_type_id IN (1, 2) AND total_amount > 0 AND status_id NOT IN (1, 7) AND order_source_id = ? AND order_date >= ? AND order_date < ?',
            [$source, $period[0]->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $period[1]->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);

        return [(int) $row['n'], (float) $row['s']];
    }

    private function order(int $type, int $status, int $source, float $amount, \DateTimeImmutable $at, ?Carrier $carrier = null, ?float $shipping = null): Order
    {
        return $this->create(Order::class, ['totalAmount' => $amount, 'shippingCost' => $shipping], function (Order $o, EntityManagerInterface $em) use ($type, $status, $source, $carrier, $at): void {
            $o->setOrderDate(\DateTime::createFromInterface($at))->setStatus($em->find(StatusCommande::class, $status))->setOrderType($em->find(OrderType::class, $type))->setOrderSource($em->find(OrderSource::class, $source))
                ->setUserId($em->find(User::class, $this->userId));
            if ($carrier) {
                $o->setCarrier($em->find(Carrier::class, $carrier->getId()));
            }
        });
    }

    /** Maintenant dans le fuseau du magasin du site de test, à midi (loin des bornes de journée) */
    private function storeNow(): \DateTimeImmutable
    {
        $tz = static::getContainer()->get(StoreClock::class)->timezone();

        return (new \DateTimeImmutable('now', $tz))->setTime(12, 0);
    }

    private function headers(): array
    {
        return ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token];
    }

    private function request(string $method, string $route): void
    {
        $this->client->request($method, $route, [], [], $this->headers());
    }

    private function get(string $route): array
    {
        $this->request('GET', $route);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 400));

        return json_decode((string) $this->client->getResponse()->getContent(), true);
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

    private function login(array $roles): void
    {
        $email = 'stats-test-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Stats')->setLastname('Test')->setRoles($roles)->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->userId = (int) $user->getId();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
