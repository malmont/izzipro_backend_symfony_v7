<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeTerminalGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * PAYS-1b (contrat validé le 12/11/2026) : une vente annulée garde ses montants (totaux et taxes gelées) ; l'avoir et le journal portent
 * l'annulation, canceled_at la marque. Rapports et export ne parlent que de leur session : annulée dans la même session, la vente sort des
 * ventes (encaissement et remboursement se compensent) ; annulée dans une autre session, l'export de la session de la vente reste identique
 * à l'octet et la session de l'annulation porte la contre-passation (bloc des retours, valeurs gelées), sans écart d'arrondi.
 */
class PosCancellationKeepsAmountsApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private int $register;
    private int $cap;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeTerminalGateway::reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-annulation-montants@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', prices_include_tax = false, pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL, pos_approval_refund = NULL, pos_approval_withdrawal = NULL, pos_approval_fund_withdrawal = NULL, pos_approval_drawer_open = false, pos_approval_close_without_count = false, pos_terminal_header_required = false, pos_pay_cash = true");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement("UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), terminal_enabled = false, stripe_reader_id = NULL, receipt_prefix = 'C1-'");
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->cap = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $this->db->executeStatement("UPDATE product SET price = 2900, special_price = NULL, is_pos = true, tax_category = NULL WHERE code = 'DEMO-CASQUETTE'");
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = 500, price = NULL WHERE id = ?', [$this->cap]);
        $provider->getEntityManager()->clear();
    }

    public function testSameSessionCancellationKeepsTheSaleAndLeavesReportsAndStatisticsAsBefore(): void
    {
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER', 'ROLE_ADMIN']);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]);
        $today = $this->today();
        $stats = fn () => [
            $this->json($this->request('GET', '/api/statistiques/chiffre-affaires/pos'))['currentMonthRevenue'],
            $this->json($this->request('GET', '/api/taxes/monthly'))['monthly_taxes_for_current_year'],
            // période commencée la veille : le journal (inaltérable) garde chaque consultation ; PosReportingApiTest compte celles du jour
            $this->json($this->request('GET', '/api/pos/statistics/cashiers?from=' . (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d') . "&to=$today"))['total']['sales']['ttc'] ?? null,
        ];
        $before = $stats();
        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->cap, 'quantity' => 2]], 'paymentMethods' => [['type' => 2, 'amount' => 66.70]], 'cashRegisterId' => $this->register]), 201);
        $id = (int) $sale['orderId'];
        $amounts = $this->amounts($id);
        $this->assertSame(5800, $amounts['order']['sub_total']);

        $cancel = $this->json($this->request('POST', "/api/order/cancel/$id", ['paymentMethod' => 2, 'cashRegisterId' => $this->register]));
        $this->assertSame($amounts, $this->amounts($id), 'la vente garde ses totaux et ses taxes');
        $this->assertSame(7, (int) $this->db->fetchOne('SELECT status_id FROM "order" WHERE id = ?', [$id]));
        $this->assertNotNull($this->db->fetchOne('SELECT canceled_at FROM "order" WHERE id = ?', [$id]));
        $this->assertSame($before, $stats(), 'chiffre d\'affaires, taxes du mois et statistiques caissier : comme avant (vente annulée = 0)');

        // Réimpression : montants de la vente et mention de l'annulation
        $ticket = $this->json($this->request('GET', '/api/pos/receipts?orderId=' . $id));
        $this->assertSame([$sale['totalAmount'], $sale['taxes']], [$ticket['totalAmount'], $ticket['taxes']]);
        $this->assertSame([$cancel['creditNoteNumber'], 'C1-' . $cancel['creditNoteNumber']], [$ticket['cancellation']['creditNoteNumber'], $ticket['cancellation']['creditNoteReference']]);
        $this->assertNotNull($ticket['cancellation']['at']);
        $this->assertNull($sale['cancellation'] ?? null, 'vente non annulée : null');

        // X et Z : la vente annulée dans la session sort des taxes et des totaux
        $x = $this->json($this->request('GET', "/api/caisse/report/x?cashRegisterId=$this->register"));
        $this->assertSame([], $x['taxes']);
        $this->assertSame(0, $x['customers']['salesWithCustomer']['total']);
        $z = $this->json($this->request('POST', '/api/caisse/close', ['cashRegisterId' => $this->register, 'countedCash' => $x['expectedCash'], 'keptFund' => 5000]))['report'];
        $this->assertSame([], $z['taxes']);

        // Export : aucune vente ni taxe, encaissement et remboursement en espèces, équilibré sans écart d'arrondi
        $e = $this->json($this->request('GET', "/api/pos/exports/accounting?from=$today&to=$today&cashRegisterId=$this->register"));
        $piece = end($e['reports'])['piece'];
        $lines = array_values(array_filter($e['entries'], fn ($l) => $l['piece'] === $piece));
        $labels = array_column($lines, 'label');
        $this->assertSame([], array_values(array_filter($labels, fn ($l) => str_contains($l, 'Ventes HT') || str_contains($l, 'collectée') || str_contains($l, 'Écart d\'arrondi'))), implode(' | ', $labels));
        $sum = fn (string $label) => array_sum(array_map(fn ($l) => $l['debit'] + $l['credit'], array_filter($lines, fn ($l) => str_contains($l['label'], $label))));
        $this->assertSame([6670, 6670], [$sum('Encaissements espèces'), $sum('Remboursements espèces')]);
        $this->assertTrue($e['totals']['balanced']);
    }

    public function testCrossSessionCancellationLeavesTheClosedSessionExportUnchangedAndReversesInTheNewSession(): void
    {
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $today = $this->today();
        // Session 1 : vente, Z, export
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]);
        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->cap, 'quantity' => 2]], 'paymentMethods' => [['type' => 2, 'amount' => 66.70]], 'cashRegisterId' => $this->register]), 201);
        $id = (int) $sale['orderId'];
        $x1 = $this->json($this->request('GET', "/api/caisse/report/x?cashRegisterId=$this->register"));
        $z1 = $this->json($this->request('POST', '/api/caisse/close', ['cashRegisterId' => $this->register, 'countedCash' => $x1['expectedCash'], 'keptFund' => 5000]))['report'];
        $piece1 = $this->json($this->request('GET', "/api/pos/exports/accounting?from=$today&to=$today&cashRegisterId=$this->register"))['reports'];
        $piece1 = end($piece1)['piece'];
        $export1 = $this->exportOf($piece1, $today);

        // Session 2 : annulation de la vente de la session 1
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]);
        $cancel = $this->json($this->request('POST', "/api/order/cancel/$id", ['paymentMethod' => 2, 'cashRegisterId' => $this->register]));
        $x2 = $this->json($this->request('GET', "/api/caisse/report/x?cashRegisterId=$this->register"));
        $this->assertSame([['TPS', -290, -5800], ['TVQ', -579, -5800]], array_map(fn ($t) => [$t['label'], $t['amount'], $t['base']], $x2['taxes']), 'X de la session 2 : contre-passation des taxes');
        $z2 = $this->json($this->request('POST', '/api/caisse/close', ['cashRegisterId' => $this->register, 'countedCash' => $x2['expectedCash'], 'keptFund' => 5000]))['report'];
        $this->assertSame($z1['taxes'], $this->json($this->request('GET', '/api/caisse/report/z/' . $z1['id']))['taxes'], 'Z de la session 1 figé');

        $this->assertSame($export1, $this->exportOf($piece1, $today), 'export de la session close identique (generic, qbo, aperçu)');
        $all = $this->json($this->request('GET', "/api/pos/exports/accounting?from=$today&to=$today&cashRegisterId=$this->register"));
        $piece2 = end($all['reports'])['piece'];
        $lines = array_values(array_filter($all['entries'], fn ($l) => $l['piece'] === $piece2));
        $by = [];
        foreach ($lines as $l) {
            $by[trim(preg_replace('/^Caisse .*?, session \d+ /', '', $l['label']))] = [$l['debit'], $l['credit']];
        }
        $ticket = 'C1-' . $sale['receiptNumber'];
        $this->assertSame([5800, 0], $by["Retours HT, dont annulation de la vente n° $ticket"] ?? null, json_encode($by, JSON_UNESCAPED_UNICODE));
        $this->assertSame([[290, 0], [579, 0]], [$by['TPS sur retours'] ?? null, $by['TVQ sur retours'] ?? null]);
        $this->assertSame([0, 6670], $by['Remboursements espèces'] ?? null);
        $this->assertSame([1, 0], $by['Arrondi des espèces rendu'] ?? null, 'arrondi rendu par le remboursement (perçu à la vente en session 1)');
        $this->assertArrayNotHasKey('Ventes HT', $by, 'pas de vente en session 2');
        $this->assertArrayNotHasKey('Encaissements espèces', $by, 'les encaissements de la vente restent en session 1');
        $this->assertArrayNotHasKey('Écart d\'arrondi', $by, 'contre-passation équilibrée ' . json_encode($by, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($all['totals']['balanced']);
        $this->assertSame($cancel['creditNoteNumber'], $this->json($this->request('GET', '/api/pos/receipts?orderId=' . $id))['cancellation']['creditNoteNumber']);
    }

    /** @return array{generic: list<string>, qbo: list<string>, preview: list<array>} lignes de l'export d'une pièce (rapport Z) */
    private function exportOf(string $piece, string $day): array
    {
        $q = "from=$day&to=$day&cashRegisterId=$this->register";
        $generic = array_values(array_filter(explode("\n", (string) $this->request('GET', "/api/pos/exports/accounting/entries.csv?$q")->getContent()), fn ($l) => str_contains($l, $piece)));
        $qbo = array_values(array_filter(explode("\n", (string) $this->request('GET', "/api/pos/exports/accounting/entries.csv?$q&format=qbo")->getContent()), fn ($l) => str_contains($l, $piece)));
        $preview = array_values(array_filter($this->json($this->request('GET', "/api/pos/exports/accounting?$q"))['entries'], fn ($l) => $l['piece'] === $piece));
        $this->assertNotEmpty($generic);

        return ['generic' => $generic, 'qbo' => $qbo, 'preview' => $preview];
    }

    private function amounts(int $id): array
    {
        return [
            'order' => array_map('intval', $this->db->fetchAssociative('SELECT sub_total, total_tax, total_amount FROM "order" WHERE id = ?', [$id])),
            'taxes' => $this->db->fetchAllAssociative('SELECT tax_label, amount, base FROM order_tax WHERE order_tax_id = ? ORDER BY id', [$id]),
        ];
    }

    private function today(): string
    {
        return static::getContainer()->get(\App\Services\CaisseService\StoreClock::class)->day(new \DateTimeImmutable());
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
            'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null,
            'HTTP_IDEMPOTENCY_KEY' => $method === 'POST' && (str_contains($path, 'create-multi-payment') || str_contains($path, '/cancel/')) ? self::uuid() : null,
        ]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function loginAs(array $roles): User
    {
        $email = 'annulation-montants-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Annul')->setLastname('Montants')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->token = null;
        $login = $this->request('POST', '/api/login', ['username' => $email, 'password' => self::PASSWORD, 'platform' => 'pos']);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];

        return $user;
    }
}
