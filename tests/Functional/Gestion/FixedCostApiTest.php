<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\GestionService\PeriodCalendar;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot FF1, frais fixes mensuels (11/11/2026, `docs/frais-fixes-gestion.md`, contrat validé par l'application) : drapeau `fixedCost` des
 * mouvements prévus (règle par défaut de la catégorie, choix du gérant gardé, jamais pour une entrée ni un mouvement ponctuel), catégorie
 * utilities, équivalent mensuel, GET /api/gestion/frais-fixes, résultat de période P2 (fixedCosts au prorata des jours, netAfterFixed,
 * remarque de double compte avec une note de frais). Période dans un futur lointain (aucune autre donnée datée là) ; mouvements vidés avant et après.
 */
class FixedCostApiTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private KernelBrowser $client;
    private string $token = '';
    private int $userId = 0;
    private ?int $period = null;

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
        $conn->executeStatement("DELETE FROM note_de_frais WHERE name LIKE 'FF %'");
        if ($this->period !== null) {
            $conn->executeStatement('DELETE FROM collections WHERE id = ?', [$this->period]);
        }
        parent::tearDown();
    }

    public function testFlagMonthlyEquivalentListAndPeriodResult(): void
    {
        $today = static::getContainer()->get(PeriodCalendar::class)->today();
        $t = fn (int $n) => $today->modify("$n days")->format('Y-m-d');
        $s = $today->modify('+8000 days');
        $d = fn (int $n) => $s->modify("$n days")->format('Y-m-d');
        $mv = fn (array $body) => $this->send('POST', '/api/gestion/tresorerie/mouvements', $body + ['direction' => 'out'], 201);

        $this->request('POST', '/api/gestion/tresorerie/mouvements', ['label' => 'X', 'category' => 'rent', 'amount' => 100, 'startDate' => $t(0), 'recurrence' => 'monthly', 'fixedCost' => 'oui']);
        $this->assertSame([422, ['fixedCost' => 'MUST_BE_BOOLEAN']], [$this->status(), $this->body()['fields']]);

        // Période de 30 jours : loyer (couvre tout), salaires hebdomadaires sur 10 jours, assurance annuelle, prêt coché, énergie active aujourd'hui
        $rent = $mv(['label' => 'Loyer', 'category' => 'rent', 'amount' => 150000, 'startDate' => $d(-100), 'recurrence' => 'monthly']);
        $this->assertSame([true, true, 150000], [$rent['fixedCost'], $rent['fixedCostDefault'], $rent['monthlyEquivalent']]);
        $salaries = $mv(['label' => 'Paie', 'category' => 'salaries', 'amount' => 50000, 'startDate' => $d(10), 'endDate' => $d(19), 'recurrence' => 'weekly']);
        $this->assertSame(216667, $salaries['monthlyEquivalent'], 'hebdomadaire × 52 ÷ 12');
        $insurance = $mv(['label' => 'Assurance', 'category' => 'insurance', 'amount' => 120000, 'startDate' => $d(-400), 'recurrence' => 'yearly']);
        $this->assertSame(10000, $insurance['monthlyEquivalent'], 'annuel ÷ 12');
        $loan = $mv(['label' => 'Prêt', 'category' => 'loan', 'amount' => 80000, 'startDate' => $d(-30), 'recurrence' => 'monthly']);
        $this->assertSame([false, true], [$loan['fixedCost'], $loan['fixedCostDefault']], 'remboursement de prêt : capital, pas une charge');
        $loanFee = $mv(['label' => 'Frais de dossier du prêt', 'category' => 'loan', 'amount' => 30000, 'startDate' => $d(-30), 'recurrence' => 'monthly', 'fixedCost' => true]);
        $this->assertSame([true, false], [$loanFee['fixedCost'], $loanFee['fixedCostDefault']], 'coché par le gérant');
        $notFixed = $mv(['label' => 'Loyer du dépôt (sous-loué)', 'category' => 'rent', 'amount' => 99999, 'startDate' => $d(-30), 'recurrence' => 'monthly', 'fixedCost' => false]);
        $this->assertFalse($notFixed['fixedCost']);
        $this->assertFalse($this->send('POST', '/api/gestion/tresorerie/mouvements', ['label' => 'Remboursement énergie', 'category' => 'utilities', 'direction' => 'in', 'amount' => 6000, 'startDate' => $d(-30), 'recurrence' => 'monthly', 'fixedCost' => true], 201)['fixedCost'], 'une entrée n\'est jamais un frais fixe');
        $this->assertFalse($mv(['label' => 'Cotisation', 'category' => 'subscription', 'amount' => 5000, 'startDate' => $d(5), 'recurrence' => 'none', 'fixedCost' => true])['fixedCost'], 'ponctuel : jamais');
        $this->assertFalse($mv(['label' => 'Divers', 'category' => 'other', 'amount' => 7000, 'startDate' => $d(-30), 'recurrence' => 'monthly'])['fixedCost']);
        $this->assertFalse($mv(['label' => 'Taxes', 'category' => 'tax', 'amount' => 7000, 'startDate' => $d(-30), 'recurrence' => 'monthly'])['fixedCost'], 'taxes : hors frais fixes par défaut');
        $energy = $mv(['label' => 'Électricité', 'category' => 'utilities', 'amount' => 4500, 'startDate' => $t(-30), 'recurrence' => 'monthly']);
        $this->assertSame([true, 'Énergie et télécoms'], [$energy['fixedCost'], $energy['categoryLabel']]);

        // PATCH : le choix du gérant est gardé ; null revient à la règle
        $this->assertTrue($this->send('PATCH', '/api/gestion/tresorerie/mouvements/' . $loanFee['id'], ['amount' => 30000], 200)['fixedCost'], 'choix gardé');
        $back = $this->send('PATCH', '/api/gestion/tresorerie/mouvements/' . $notFixed['id'], ['fixedCost' => null], 200);
        $this->assertSame([true, true], [$back['fixedCost'], $back['fixedCostDefault']]);
        $this->send('PATCH', '/api/gestion/tresorerie/mouvements/' . $notFixed['id'], ['fixedCost' => false], 200);

        // Liste : actifs aujourd'hui (l'énergie seule), ou tous
        $list = $this->get('/api/gestion/frais-fixes?locale=fr');
        $this->assertSame(['CAD', 4500, [['category' => 'utilities', 'label' => 'Énergie et télécoms', 'monthly' => 4500]]], [$list['currency'], $list['monthlyTotal'], $list['byCategory']]);
        $this->assertSame([$energy['id']], array_column($list['items'], 'id'));
        $this->assertSame(['id', 'label', 'category', 'categoryLabel', 'amount', 'recurrence', 'monthlyEquivalent', 'startDate', 'endDate', 'active', 'fixedCostDefault'], array_keys($list['items'][0]));
        $all = $this->get('/api/gestion/frais-fixes?all=1');
        $this->assertEqualsCanonicalizing([$rent['id'], $salaries['id'], $insurance['id'], $loanFee['id'], $energy['id']], array_column($all['items'], 'id'));
        $this->assertSame(4500, $all['monthlyTotal'], 'total mensuel : actifs seulement');

        // Résultat de période : prorata des jours couverts, net inchangé, après frais fixes en plus ; note de frais suspecte signalée
        $this->period = (int) $this->em()->getConnection()->fetchOne(
            "INSERT INTO collections (id, budget_collection, start_date_collection, end_date_collection, del, nom_collection, is_closed, kind, period_key) VALUES (nextval('collections_id_seq'), 0, ?, ?, false, 'FF', false, 'period', ?) RETURNING id",
            [$d(0) . ' 00:00:00', $d(29) . ' 00:00:00', 'ff-' . bin2hex(random_bytes(3))]
        );
        $this->em()->getConnection()->executeStatement("INSERT INTO note_de_frais (id, name, montant, date) VALUES (nextval('note_de_frais_id_seq'), 'FF Loyer de juin', 1000, ?)", [$d(3)]);
        $this->endOfWrite();
        $r = $this->get('/api/periodes/' . $this->period . '/resultat');
        $f = $r['fixedCosts'];
        $this->assertSame([263068, 30], [$f['total'], $f['days']]);
        $this->assertSame(['rent' => 147945, 'salaries' => 71233, 'insurance' => 9863, 'utilities' => 4438, 'loan' => 29589], array_column($f['byCategory'], 'amount', 'category'), 'équivalent mensuel × 12 ÷ 365 par jour couvert');
        $this->assertSame([-1000, -264068, null], [$r['result']['net'], $r['result']['netAfterFixed'], $r['result']['netAfterFixedRate']]);
        $this->assertSame(['FIXED_COSTS_PRORATED', 'FIXED_COSTS_MAY_OVERLAP_NOTES'], array_column($f['caveats'], 'code'));
        $this->assertSame(['FF Loyer de juin'], $f['caveats'][1]['notes']);
    }

    private function clean(): void
    {
        $this->em()->getConnection()->executeStatement('DELETE FROM gestion_cash_movement');
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
        $email = 'gestion-ff-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('Ff')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->userId = (int) $user->getId();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
