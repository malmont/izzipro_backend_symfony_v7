<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\Order;
use App\Entity\OrderTax;
use App\Entity\Tax;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\OrderService\TaxCalculationService;
use App\Services\OrderService\TaxEngine;
use App\Services\OrderService\TaxInclusive;
use App\Services\PosOfflineService\PosOfflineCalculator;
use App\Services\PosOfflineService\PosOfflineSyncService;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeTerminalGateway;
use DoctrineMigrations\Version20261223100000;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * PAYS-1c (contrat validé le 12/11/2026) : prix taxes comprises au Québec. Calcul TTC au millionième (TVQ 9,975 % = 99750), identique au
 * calcul en dix-millièmes pour tout taux multiple de 0,01 % ; règle hors ligne v5 servie à la tablette qui l'annonce (X-Pos-Offline-Rules: v5,
 * qui vaut aussi v4) ; sans elle, un site TTC à taux non multiple de 0,01 % n'a pas de mode hors ligne (RULE_V5_REQUIRED) et une vente
 * calculée avec un pack antérieur est refusée (PACK_RULE_MISMATCH, forçable) ; packs v3 et v4 inchangés.
 */
class PosOfflineV5ApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private ?string $terminal = null;
    private int $terminalId = 0;
    private ?string $rules = null;
    private int $register;
    private int $cap;
    /** @var array<int, array{name: string, rate: float}> taux d'origine, remis en fin de test */
    private array $savedTaxes = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeTerminalGateway::reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-v5@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', prices_include_tax = false, pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL, pos_approval_refund = NULL, pos_approval_withdrawal = NULL, pos_approval_fund_withdrawal = NULL, pos_approval_drawer_open = false, pos_approval_close_without_count = false, pos_terminal_header_required = false,
            pos_pay_cash = true, pos_return_max_days = 30, pos_offline_enabled = true, pos_offline_max_hours = 24, pos_offline_max_queued_sales = 100, pos_offline_max_queued_amount = 500000, pos_offline_price_tolerance = 5, pos_offline_pack_validity_hours = 24");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement("UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), terminal_enabled = false, stripe_reader_id = NULL, receipt_prefix = 'C1-'");
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->cap = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $this->db->executeStatement("UPDATE product SET price = 2900, special_price = NULL, is_pos = true, tax_category = NULL WHERE code = 'DEMO-CASQUETTE'");
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = 500, price = NULL WHERE id = ?', [$this->cap]);
        $this->db->executeStatement('UPDATE tax SET archived_at = NULL, valid_from = NULL, valid_to = NULL');
        foreach (['tax', 'order_tax'] as $table) { // séquences alignées (lignes du modèle copiées en SQL)
            $this->db->executeStatement("SELECT setval('{$table}_id_seq', GREATEST((SELECT COALESCE(MAX(id), 0) FROM $table), (SELECT last_value FROM {$table}_id_seq)))");
        }
        foreach ($this->db->fetchAllAssociative("SELECT id, name, rate FROM tax WHERE name IN ('TPS', 'TVQ')") as $t) {
            $this->savedTaxes[(int) $t['id']] = ['name' => $t['name'], 'rate' => (float) $t['rate']];
        }
        $provider->getEntityManager()->clear();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedTaxes as $id => $t) { // taux d'origine (modifiés en SQL direct, comme une ancienne édition de la table)
            $this->db->executeStatement('UPDATE tax SET name = ?, rate = ? WHERE id = ?', [$t['name'], $t['rate'], $id]);
        }
        parent::tearDown();
    }

    public function testMillionthsGiveExactlyTheTenThousandthsResultsForRatesInUse(): void
    {
        // Ancienne formule (dix-millièmes), recopiée telle qu'elle était avant PAYS-1c
        $oldBase = function (int $ttc, int $r): int { $d = 10000 + $r; return $r <= 0 ? $ttc : intdiv(2 * $ttc * 10000 + $d, 2 * $d); };
        $oldTax = fn (int $base, int $r): int => intdiv(2 * $base * $r + 10000, 20000);
        $amounts = range(1, 30000);
        for ($a = 30011; $a <= 1000000; $a += 487) {
            $amounts[] = $a; // jusqu'à 10 000,00
        }
        foreach ([500, 1300, 1500, 2000, 1000, 550, 210, 1400, 700, 600, 850] as $r) { // 5 %, 13 %, 15 %, 20 %, 10 %, 5,5 %, 2,1 %, 14 %, 7 %, 6 %, 8,5 %
            foreach ([1, 3, 7] as $qty) {
                foreach ($amounts as $unit) {
                    $ttc = $unit * $qty;
                    $base = TaxInclusive::base($ttc, $r * 100);
                    if ($base !== $oldBase($ttc, $r) || TaxInclusive::taxOn($base, $r * 100) !== $oldTax($base, $r)) {
                        $this->fail("écart à $ttc cents, taux $r dix-millièmes");
                    }
                }
            }
        }
        // Groupe à deux taxes exactes (20 % + 5,5 % sur la même ligne) : somme des taux
        foreach ($amounts as $ttc) {
            if (TaxInclusive::base($ttc, 255000) !== $oldBase($ttc, 2550)) {
                $this->fail("écart à $ttc cents, taux combiné");
            }
        }
        $this->assertSame([99750, true, false, true], [TaxInclusive::ratePpm(0.09975), TaxInclusive::isExactRate(0.09975), TaxInclusive::isLegacyExactRate(0.09975), TaxInclusive::isLegacyExactRate(0.055)]);
        $this->assertNull(TaxInclusive::ratePpm(0.0997512345), 'taux non exact au millionième');
        $this->assertSame([2522, 126], [TaxInclusive::base(2900, 149750), TaxInclusive::taxOn(2522, 50000)], '29,00 $ TTC au Québec : base 25,22, TPS 1,26, TVQ 2,52');
    }

    public function testSharedV5CasesAreReproducedByTheServer(): void
    {
        $this->loginAs(['ROLE_USER_POS']);
        $cases = $this->json($this->request('GET', '/api/pos/offline-cases'));
        $this->assertSame([PosOfflineCalculator::RULE, PosOfflineCalculator::RULE_V5], [$cases['rule'], $cases['ruleV5']], 'les cas v3 gardent leur règle');
        $this->assertGreaterThanOrEqual(8, count($cases['casesV5']));
        foreach ($cases['casesV5'] as $case) {
            $r = PosOfflineCalculator::compute($case['lines'], $case['ticketDiscount'], $case['taxes'], $case['step'], $case['cashReceived'], true);
            $got = ['lines' => $r['lines'], 'subtotal' => $r['subtotal'], 'discountTotal' => $r['discountTotal'], 'taxableSubtotal' => $r['taxableSubtotal'],
                'taxes' => array_map(fn ($t) => ['label' => $t['label'], 'base' => $t['base'], 'amount' => $t['amount']], $r['taxes']), 'total' => $r['total'], 'cashTotal' => $r['cashTotal'], 'cashRounding' => $r['cashRounding'], 'change' => $r['change']];
            $this->assertEquals($case['expected'], $got, 'cas ' . $case['id']);
            $this->assertSame($case['expected']['total'], $case['expected']['subtotal'], 'TTC : total = sous-total');
            foreach ($case['taxes'] as $t) {
                $this->assertSame((int) round($t['rate'] * 1000000), $t['ratePpm']);
            }
        }
        $byId = array_column($cases['casesV5'], null, 'id');
        $this->assertSame([['label' => 'TPS', 'base' => 2522, 'amount' => 126], ['label' => 'TVQ', 'base' => 2522, 'amount' => 252]], $byId['v5-ttc-qc-29-dollars']['expected']['taxes'], 'vérifié à la main');
        $this->assertSame([['label' => 'TPS', 'base' => 8698, 'amount' => 435], ['label' => 'TVQ', 'base' => 8698, 'amount' => 867]], $byId['v5-ttc-qc-100-dollars']['expected']['taxes'], 'la TVQ porte l\'écart');
    }

    public function testHtSitePacksAreUnchangedAndV5IsServedOnlyWhenAnnounced(): void
    {
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $get = function (?string $rules) {
            $this->rules = $rules;
            $response = $this->request('GET', "/api/pos/offline-pack?cashRegisterId=$this->register");
            $this->rules = null;

            return [$this->json($response), trim((string) $response->headers->get('ETag'), '"')];
        };
        [$v3, $etag3] = $get(null);
        [$v4, $etag4] = $get('v4');
        $this->assertSame([PosOfflineCalculator::RULE, PosOfflineCalculator::RULE], [$v3['calculationRule'], $v3['settings']['offline']['rule']]);
        $this->assertSame([['id', 'label', 'rate', 'category'], [true, null]], [array_keys($v3['taxes'][0]), [$v3['settings']['offline']['enabled'], $v3['settings']['offline']['reason']]], 'pack v3 : aucune clé ajoutée');
        $content = array_diff_key($v3, array_flip(['packVersion', 'generatedAt', 'serverTime', 'validUntil']));
        $this->assertSame($etag3, hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE)), 'ETag = empreinte du contenu servi, sans champ caché');
        $this->assertSame([$etag3, $v3['packVersion']], [$etag4, $v4['packVersion']], 'interrupteur des promotions de variante éteint : pack v4 = pack v3, à l\'octet');
        $this->assertSame($content, array_diff_key($v4, array_flip(['packVersion', 'generatedAt', 'serverTime', 'validUntil'])));
        [$v5, $etag5] = $get('v4,v5');
        $this->assertSame([PosOfflineCalculator::RULE_V5, PosOfflineCalculator::RULE_V5, [50000, 99750]], [$v5['calculationRule'], $v5['settings']['offline']['rule'], array_column($v5['taxes'], 'ratePpm')]);
        $this->assertNotSame($etag3, $etag5);
        $this->assertSame($etag5, $get('v5')[1], '« v5 » seul suffit');
        $this->assertSame($etag3, $get(null)[1], 'pack v3 toujours identique après un pack v5');
    }

    public function testQuebecTaxInclusiveSaleQuoteOfflineV5AndRuleMismatch(): void
    {
        // Garde : la TVQ (9,975 %) n'empêche plus les prix taxes comprises ; le verrou après la première vente reste
        try {
            static::getContainer()->get(\App\Services\OrderService\TaxInclusiveGuard::class)->assertCanEnable();
            $locked = false;
        } catch (\DomainException $e) {
            $this->assertStringNotContainsString('millionième', $e->getMessage());
            $this->assertStringContainsString('verrouillés', $e->getMessage(), 'seul refus possible ici : le verrou après la première vente');
            $locked = true;
        }
        $this->assertSame((int) $this->db->fetchOne('SELECT COUNT(*) FROM "order"') > 0, $locked);
        $this->db->executeStatement('UPDATE entreprise SET prices_include_tax = true');
        try {
            $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
            $this->terminal = $this->posTerminal();
            $seller = $this->user(['ROLE_USER_POS']);
            $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]);

            // Devis et vente en ligne : 29,00 $ TTC = base 25,22 + TPS 1,26 + TVQ 2,52
            $q = $this->json($this->request('POST', '/api/pos/quote', ['items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'cashRegisterId' => $this->register]));
            $this->assertSame([2900, true, [['TPS', 2522, 126], ['TVQ', 2522, 252]]], [$q['total'], $q['pricesIncludeTax'], array_map(fn ($t) => [$t['label'], $t['base'], $t['amount']], $q['taxes'])]);
            $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'paymentMethods' => [['type' => 2, 'amount' => 29.00]], 'cashRegisterId' => $this->register]), 201);
            $this->assertSame([['TPS', 50000, true, 'table'], ['TVQ', 99750, true, 'table']], array_map(fn ($r) => [$r['tax_label'], $r['tax_rate_ppm'], $r['prices_include_tax'], $r['tax_source']], $this->frozen((int) $sale['orderId'])));
            $this->assertSame([2900, 378], array_map('intval', $this->db->fetchNumeric('SELECT total_amount, total_tax FROM "order" WHERE id = ?', [$sale['orderId']])));

            // Tablette qui n'annonce pas la v5 : pas de mode hors ligne, motif clair ; réglages de la caisse : même motif
            $old = $this->json($this->request('GET', "/api/pos/offline-pack?cashRegisterId=$this->register"));
            $this->assertSame([false, 'RULE_V5_REQUIRED', PosOfflineCalculator::RULE], [$old['settings']['offline']['enabled'], $old['settings']['offline']['reason'], $old['calculationRule']]);
            $this->assertSame('RULE_V5_REQUIRED', $this->json($this->request('GET', '/api/pos/settings'))['offline']['reason']);
            // Tablette v5 : mode hors ligne, règle v5, taux au millionième
            $this->rules = 'v5';
            $pack = $this->json($this->request('GET', "/api/pos/offline-pack?cashRegisterId=$this->register"));
            $this->assertSame([true, null, PosOfflineCalculator::RULE_V5, [50000, 99750], true], [$pack['settings']['offline']['enabled'], $pack['settings']['offline']['reason'], $pack['calculationRule'], array_column($pack['taxes'], 'ratePpm'), $pack['site']['pricesIncludeTax']]);
            $this->assertTrue($this->json($this->request('GET', '/api/pos/settings'))['offline']['enabled']);
            $s1 = $this->offlineSale($seller, 'P-' . $this->terminalId . '-1', [['productVariantId' => $this->cap, 'quantity' => 1]], $pack);
            $this->assertSame([2900, [126, 252]], [$s1['totals']['total'], array_column($s1['totals']['taxes'], 'amount')]);
            $a = $this->json($this->request('POST', '/api/pos/offline-sales', ['packVersion' => $pack['packVersion'], 'deviceTime' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM), 'sales' => [$s1]]))['results'][0];
            $this->assertSame(['accepted', 0, []], [$a['status'], $a['priceDrift']['amount'], $a['taxRateDrift']], json_encode($a));
            $this->assertSame([['TPS', 50000, 'offline'], ['TVQ', 99750, 'offline']], array_map(fn ($r) => [$r['tax_label'], $r['tax_rate_ppm'], $r['tax_source']], $this->frozen((int) $a['orderId'])));

            // Vente arrivée avec le pack d'une tablette antérieure (règle v3) : refusée, forçable par un responsable
            $s2 = $this->offlineSale($seller, 'P-' . $this->terminalId . '-2', [['productVariantId' => $this->cap, 'quantity' => 1]], $old, $s1['chain']['hash']);
            $b = $this->json($this->request('POST', '/api/pos/offline-sales', ['packVersion' => $old['packVersion'], 'deviceTime' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM), 'sales' => [$s2]]))['results'][0];
            $this->assertSame(['rejected', 'PACK_RULE_MISMATCH', true], [$b['status'], $b['error'], $b['forceable']], json_encode($b));
            $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM pos_offline_sale WHERE provisional_number = ? AND order_id IS NOT NULL', ['P-' . $this->terminalId . '-2']));
        } finally {
            $this->db->executeStatement('UPDATE entreprise SET prices_include_tax = false');
            $this->rules = null;
        }
    }

    private function frozen(int $orderId): array
    {
        return array_map(fn ($r) => ['tax_label' => $r['tax_label'], 'tax_rate_ppm' => $r['tax_rate_ppm'] !== null ? (int) $r['tax_rate_ppm'] : null, 'tax_code' => $r['tax_code'], 'tax_jurisdiction' => $r['tax_jurisdiction'],
            'tax_category' => $r['tax_category'], 'prices_include_tax' => $r['prices_include_tax'], 'tax_source' => $r['tax_source']],
            $this->db->fetchAllAssociative('SELECT tax_label, tax_rate_ppm, tax_code, tax_jurisdiction, tax_category, prices_include_tax, tax_source FROM order_tax WHERE order_tax_id = ? ORDER BY id', [$orderId]));
    }

    private function em(): \Doctrine\ORM\EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }

    private function offlineSale(User $seller, string $provisional, array $items, array $pack, string $prevHash = ''): array
    {
        $lines = [];
        foreach ($items as $item) {
            foreach ($pack['products'] as $p) {
                foreach ($p['variants'] as $v) {
                    if ($v['id'] === $item['productVariantId']) {
                        $lines[] = ['unitPrice' => $v['price'] ?? $p['price'], 'quantity' => $item['quantity'], 'discounts' => []];
                    }
                }
            }
        }
        $r = PosOfflineCalculator::compute($lines, null, $pack['taxes'], $pack['site']['cashRoundingStep'], null, (bool) $pack['site']['pricesIncludeTax']);
        $sale = ['idempotencyKey' => self::uuid(), 'provisionalNumber' => $provisional, 'cashRegisterId' => $this->register, 'sellerId' => $seller->getId(), 'soldAt' => (new \DateTimeImmutable('-30 seconds'))->format(\DateTimeInterface::ATOM),
            'items' => $items, 'ticketDiscount' => null, 'payments' => [['type' => 2, 'amount' => $r['cashTotal']]], 'cashReceived' => $r['cashTotal'],
            'totals' => ['subtotal' => $r['subtotal'], 'taxes' => array_map(fn ($t) => ['label' => $t['label'], 'amount' => $t['amount']], $r['taxes']), 'total' => $r['total'], 'cashTotal' => $r['cashTotal'], 'cashRounding' => $r['cashRounding']]];
        $sale['chain'] = ['seq' => (int) substr($provisional, strrpos($provisional, '-') + 1), 'prevHash' => $prevHash, 'hash' => PosOfflineSyncService::hashOf($sale, $prevHash)];

        return $sale;
    }

    private function posTerminal(): string
    {
        $saved = $this->token;
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']);
        $key = $this->json($this->request('POST', '/api/app-keys', ['app' => 'pos', 'label' => 'Tablette v5', 'sendEmail' => false]), 201)['key'];
        $this->token = $saved;
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/app/activate', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['key' => $key, 'app' => 'pos']));
        $terminal = json_decode($this->client->getResponse()->getContent(), true)['terminal'];
        $this->terminalId = (int) $terminal['id'];

        return $terminal['token'];
    }

    private function json(Response $response, int $expected = 200): array
    {
        $this->assertSame($expected, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent(), true);
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private function request(string $method, string $path, ?array $body = null): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null, 'HTTP_X_POS_TERMINAL' => $this->terminal, 'HTTP_X_POS_OFFLINE_RULES' => $this->rules,
            'HTTP_IDEMPOTENCY_KEY' => $method === 'POST' && str_contains($path, 'create-multi-payment') ? self::uuid() : null,
        ]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function user(array $roles): User
    {
        $email = 'v5-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gel')->setLastname('Taxes')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $user;
    }

    private function loginAs(array $roles): User
    {
        $user = $this->user($roles);
        $saved = $this->terminal;
        $this->terminal = null;
        $this->token = null;
        $login = $this->request('POST', '/api/login', ['username' => $user->getEmail(), 'password' => self::PASSWORD, 'platform' => 'pos']);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];
        $this->terminal = $saved;

        return $user;
    }
}
