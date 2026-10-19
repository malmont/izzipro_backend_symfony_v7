<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Chantier C1 du POS (19/10/2026) : coupures servies par le serveur, rapport X, fermeture avec comptage, rapports Z
 * numérotés par poste et figés, report du fond gardé, arrondi des espèces au Canada (5 ¢). Montants en cents, sauf
 * paymentMethods de la vente (dollars). Données : boutique de démonstration ; casquette 29,00 $ + TPS 1,45 $ + TVQ
 * 2,89 $ = 33,34 $ (33,35 $ en espèces).
 */
class CashClosingApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private int $register;
    private int $variant;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-caisse@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement('UPDATE entreprise SET pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL');
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement('UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register))');
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->variant = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = 500 WHERE id = ?', [$this->variant]);
        $this->db->executeStatement("UPDATE product SET price = 2900 WHERE code = 'DEMO-CASQUETTE'"); // 33,34 $ TTC au Québec : arrondi +1 ¢ en espèces
        $this->db->executeStatement('UPDATE product_variant SET price = NULL WHERE id = ?', [$this->variant]);
        $this->loginAs(['ROLE_USER_POS']);
    }

    public function testDenominationsAreServedAndCountsAreValidated(): void
    {
        $list = $this->json($this->request('GET', '/api/caisse/denominations'));
        $this->assertSame([1, 2, 11, 3, 4, 5, 6, 7, 8, 9, 10], array_column($list, 'id'), 'ids du POS, 20 $ en 11, ordre d\'affichage');
        $byId = array_column($list, null, 'id');
        $this->assertSame(['id' => 11, 'label' => '20 $', 'valueCents' => 2000, 'kind' => 'bill', 'active' => true], $byId[11]);
        $this->assertSame([10000, 200, 25, 1], [$byId[1]['valueCents'], $byId[5]['valueCents'], $byId[7]['valueCents'], $byId[10]['valueCents']]);
        $this->assertSame(['coin', false], [$byId[10]['kind'], $byId[10]['active']], 'pièce de 1 ¢ inactive');

        $this->open();
        $bad = $this->request('POST', '/api/caisse/deposit', json_encode(['amount' => 500, 'cashRegisterId' => $this->register, 'cashDetails' => [['typeCash' => 999, 'nombreItems' => 1]]]));
        $this->assertSame(400, $bad->getStatusCode(), 'coupure inconnue : 400 (500 avant)');
        $this->assertSame('INVALID_CASH_DETAILS', json_decode($bad->getContent(), true)['error']);
        $ok = $this->request('POST', '/api/caisse/deposit', json_encode(['amount' => 2500, 'cashRegisterId' => $this->register, 'cashDetails' => [['typeCash' => 11, 'nombreItems' => 1], ['typeCash' => 4, 'nombreItems' => 1]]]));
        $this->assertSame(201, $ok->getStatusCode(), $ok->getContent());
    }

    public function testXReportCloseWithCountAndZReports(): void
    {
        $opened = json_decode($this->request('POST', '/api/caisse/open', json_encode(['cashRegisterId' => $this->register, 'cashFund' => 10000]))->getContent(), true);
        $opening = (int) $opened['amountTotal'] - 10000;

        // Devis de caisse : total et total espèces arrondi aux 5 ¢ ; devis web : inchangé (pas de cashTotal).
        // Le prix de la casquette peut avoir été changé par un autre test : tout part du devis.
        $quote = $this->json($this->request('POST', '/api/cart/quote', json_encode(['items' => [['productVariantId' => $this->variant, 'quantity' => 1]], 'cashRegisterId' => $this->register])));
        $total = $quote['total'];
        $cashTotal = $quote['cashTotal'];
        $r = $cashTotal - $total;
        $this->assertSame((int) (round($total / 5) * 5), $cashTotal);
        $this->assertLessThanOrEqual(2, abs($r));
        $web = $this->json($this->request('POST', '/api/cart/quote', json_encode(['items' => [['productVariantId' => $this->variant, 'quantity' => 1]]])));
        $this->assertArrayNotHasKey('cashTotal', $web, 'boutique en ligne : réponse inchangée');

        // Vente espèces arrondie (+1 ¢), vente carte, dépôt, retrait, remboursement en espèces (arrondi)
        $cashSale = $this->sell([['type' => 2, 'amount' => $cashTotal / 100]]);
        $this->assertSame(201, $cashSale->getStatusCode(), $cashSale->getContent());
        $receipt = json_decode($cashSale->getContent(), true);
        $this->assertEqualsWithDelta([$total / 100, $r / 100, $cashTotal / 100], [$receipt['totalAmount'], $receipt['cashRounding'], $receipt['amountDue']], 0.0001, 'ticket : arrondi des espèces');
        $this->assertSame($r, (int) $this->db->fetchOne('SELECT cash_rounding FROM "order" WHERE id = ?', [$receipt['orderId']]));
        $this->assertSame(201, $this->sell([['type' => 1, 'amount' => $total / 100]])->getStatusCode(), 'carte : total exact');
        $mismatch = $this->sell([['type' => 2, 'amount' => ($cashTotal - 5) / 100]]);
        $this->assertSame(400, $mismatch->getStatusCode());
        $this->assertSame(['PAYMENT_TOTAL_MISMATCH', $total, $cashTotal], [json_decode($mismatch->getContent(), true)['error'], json_decode($mismatch->getContent(), true)['expectedTotal'], json_decode($mismatch->getContent(), true)['cashTotal']]);
        $this->assertSame(201, $this->request('POST', '/api/caisse/deposit', json_encode(['amount' => 1000, 'cashRegisterId' => $this->register]))->getStatusCode());
        $this->assertSame(201, $this->request('POST', '/api/caisse/withdraw', json_encode(['amount' => 500, 'cashRegisterId' => $this->register]))->getStatusCode());
        $refunded = $this->sell([['type' => 2, 'amount' => $cashTotal / 100]]);
        $refundedId = json_decode($refunded->getContent(), true)['orderId'];
        $this->assertSame(200, $this->request('POST', "/api/order/cancel/$refundedId", json_encode(['paymentMethod' => 2]))->getStatusCode());

        $x = $this->json($this->request('GET', '/api/caisse/report/x?cashRegisterId=' . $this->register));
        $this->assertSame($this->register, $x['cashRegister']['id']);
        $this->assertSame([$opening, 3, 3 * $total, 2 * $cashTotal], [$x['openingAmount'], $x['sales']['count'], $x['sales']['total'], $x['sales']['cash']]);
        $methods = array_column($x['sales']['byPaymentMethod'], null, 'type');
        $this->assertSame([2, 2 * $cashTotal], [$methods[2]['count'], $methods[2]['total']], 'espèces reçues (arrondies)');
        $this->assertSame([1, $total], [$methods[1]['count'], $methods[1]['total']]);
        $this->assertSame([1, $total, $cashTotal], [$x['refunds']['count'], $x['refunds']['total'], $x['refunds']['cash']], 'vente payée en espèces : on rend les espèces encaissées');
        $this->assertSame(['deposits' => 1000, 'withdrawals' => 500, 'fundDeposits' => 10000, 'fundWithdrawals' => 0], $x['movements']);
        $this->assertSame(['count' => $r === 0 ? 0 : 3, 'total' => $r], $x['cashRounding'], '+r +r −r');
        $this->assertSame(['count' => 0, 'total' => 0], $x['discounts']);
        $this->assertNotEmpty($x['taxes']);
        $this->assertSame(['TPS', 'TVQ'], array_column($x['taxes'], 'label'));
        $expected = $opening + 10000 + 2 * $cashTotal - $cashTotal + 1000 - 500;
        $this->assertSame($expected, $x['expectedCash']);
        $this->assertSame($expected, (int) $this->db->fetchOne('SELECT amount_total FROM caisse WHERE cash_register_id = ? AND is_open', [$this->register]), 'X = solde tenu par la caisse');
        $this->assertSame('Mémoires Vivantes', $x['store']['name'] ?? null);
        $this->assertSame(0, $x['discrepancyThreshold']);

        // Fermeture : contrôles du comptage
        $details = [['typeCash' => 1, 'nombreItems' => 1]];
        $this->assertSame('COUNT_MISMATCH', $this->closeError(['countedCash' => 5, 'cashDetails' => $details]));
        $this->assertSame('DISCREPANCY_REASON_REQUIRED', $this->closeError(['countedCash' => $expected - 50]));
        $this->assertSame('INVALID_AMOUNT', $this->closeError(['countedCash' => $expected, 'keptFund' => $expected + 1]));
        $this->assertSame('INVALID_AMOUNT', $this->closeError(['countedCash' => -1]));
        $this->assertSame('INVALID_AMOUNT', $this->closeError(['keptFund' => 100]), 'keptFund sans comptage');

        $before = (int) ($this->db->fetchOne('SELECT MAX(number) FROM cash_register_report WHERE cash_register_id = ?', [$this->register]) ?: 0);
        $closed = $this->request('POST', '/api/caisse/close', json_encode(['cashRegisterId' => $this->register, 'countedCash' => $expected - 50, 'discrepancyReason' => 'Pièce tombée', 'keptFund' => 15000]));
        $this->assertSame(200, $closed->getStatusCode(), $closed->getContent());
        $body = json_decode($closed->getContent(), true);
        $this->assertSame('Caisse closed successfully', $body['message'], 'champs d\'avant conservés');
        $z = $body['report'];
        $this->assertSame($before + 1, $z['number'], 'suite sans trou par poste');
        $this->assertSame([$expected - 50, $expected, -50, 'Pièce tombée', 15000, $expected - 50 - 15000], [$z['countedCash'], $z['expectedCash'], $z['discrepancy'], $z['discrepancyReason'], $z['keptFund'], $z['bankDeposit']]);
        $this->assertSame(3, $z['sales']['count']);
        $this->assertNotEmpty($z['closedBy']['email']);

        $again = $this->request('POST', '/api/caisse/close', json_encode(['cashRegisterId' => $this->register, 'countedCash' => 0]));
        $this->assertSame(400, $again->getStatusCode());
        $this->assertSame(['CASH_REGISTER_CLOSED', $z['id'], $z['number']], [json_decode($again->getContent(), true)['error'], json_decode($again->getContent(), true)['lastReport']['id'], json_decode($again->getContent(), true)['lastReport']['number']], 'double envoi : pas de second Z');
        $this->assertSame(409, $this->request('GET', '/api/caisse/report/x?cashRegisterId=' . $this->register)->getStatusCode());

        // Réouverture : seul le fond gardé est reporté ; fermeture sans comptage : Z quand même, numéro suivant
        $reopened = json_decode($this->request('POST', '/api/caisse/open', json_encode(['cashRegisterId' => $this->register]))->getContent(), true);
        $this->assertSame([15000, 15000], [(int) $reopened['amountTotal'], (int) $reopened['fonDeCaisse']]);
        $this->sell([['type' => 2, 'amount' => $cashTotal / 100]]);
        $plain = json_decode($this->request('POST', '/api/caisse/close', json_encode(['cashRegisterId' => $this->register]))->getContent(), true);
        $this->assertSame([$z['number'] + 1, null, null, 15000 + $cashTotal], [$plain['report']['number'], $plain['report']['countedCash'], $plain['report']['keptFund'], $plain['report']['expectedCash']]);

        // Liste et détail ; le Z est figé (les ventes suivantes ne le changent pas)
        $list = $this->json($this->request('GET', '/api/caisse/report/z?cashRegisterId=' . $this->register . '&from=' . date('Y-m-d')));
        $this->assertSame([$plain['report']['id'], $z['id']], array_slice(array_column($list, 'id'), 0, 2), 'plus récent d\'abord');
        $detail = $this->json($this->request('GET', '/api/caisse/report/z/' . $z['id']));
        $this->assertSame([$z['number'], 3, -50, 'Mémoires Vivantes'], [$detail['number'], $detail['sales']['count'], $detail['discrepancy'], $detail['store']['name']]);
        $this->assertSame(404, $this->request('GET', '/api/caisse/report/z/999999')->getStatusCode());
        $this->assertSame('INVALID_DATE', json_decode($this->request('GET', '/api/caisse/report/z?from=hier')->getContent(), true)['error']);
    }

    public function testCountingByDenominationsThresholdAndNumberingPerRegister(): void
    {
        $this->db->executeStatement('UPDATE entreprise SET pos_discrepancy_threshold = 100');
        $this->open();
        $expected = $this->json($this->request('GET', '/api/caisse/report/x?cashRegisterId=' . $this->register))['expectedCash'];
        // Comptage par coupures (le serveur calcule countedCash) ; écart dans le seuil : pas de motif exigé
        $details = $this->denominationsFor($expected - 60);
        $closed = $this->request('POST', '/api/caisse/close', json_encode(['cashRegisterId' => $this->register, 'cashDetails' => $details]));
        $this->assertSame(200, $closed->getStatusCode(), $closed->getContent());
        $z = json_decode($closed->getContent(), true)['report'];
        $this->assertSame([$expected - 60, -60, null], [$z['countedCash'], $z['discrepancy'], $z['discrepancyReason']]);
        $this->assertSame($expected - 60, array_sum(array_column($z['cashDetails'], 'totalCents')));
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM cash_details d JOIN transaction_caisse t ON t.id = d.transaction_caisse_id WHERE t.caisse_id = ? AND t.transaction_type_id = 5", [$z['sessionId']]) >= 1 ? 1 : 0, 'comptage gardé sur la transaction de fermeture');

        // Second poste : sa propre suite de numéros
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']);
        $second = (int) json_decode($this->request('POST', '/api/cash-registers', json_encode(['name' => 'Poste Z ' . bin2hex(random_bytes(2))]))->getContent(), true)['id'];
        $this->request('POST', '/api/caisse/open', json_encode(['cashRegisterId' => $second]));
        $firstOfSecond = json_decode($this->request('POST', '/api/caisse/close', json_encode(['cashRegisterId' => $second, 'countedCash' => 0]))->getContent(), true)['report'];
        $this->assertSame(1, $firstOfSecond['number'], 'chaque poste commence à 1');
        $this->assertSame($second, $firstOfSecond['cashRegister']['id']);
    }

    /** Coupures pour un montant (cents, multiple de 5) */
    private function denominationsFor(int $cents): array
    {
        $details = [];
        foreach ([1 => 10000, 2 => 5000, 11 => 2000, 3 => 1000, 4 => 500, 5 => 200, 6 => 100, 7 => 25, 8 => 10, 9 => 5] as $id => $value) {
            $count = intdiv($cents, $value);
            if ($count > 0) {
                $details[] = ['typeCash' => $id, 'nombreItems' => $count];
                $cents -= $count * $value;
            }
        }
        $this->assertSame(0, $cents);

        return $details;
    }

    private function closeError(array $body): string
    {
        $response = $this->request('POST', '/api/caisse/close', json_encode(['cashRegisterId' => $this->register] + $body));
        $this->assertSame(400, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent(), true)['error'];
    }

    private function sell(array $paymentMethods): Response
    {
        return $this->request('POST', '/api/order/create-multi-payment', json_encode([
            'items' => [['productVariantId' => $this->variant, 'quantity' => 1]], 'paymentMethods' => $paymentMethods, 'cashRegisterId' => $this->register,
        ]));
    }

    private function open(): void
    {
        $this->assertSame(201, $this->request('POST', '/api/caisse/open', json_encode(['cashRegisterId' => $this->register]))->getStatusCode());
    }

    private function json(Response $response): array
    {
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent(), true);
    }

    private function request(string $method, string $path, ?string $body = null): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null,
        ]), $body);

        return $this->client->getResponse();
    }

    private function loginAs(array $roles): void
    {
        $email = 'cloture-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Clôture')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->token = null;
        $login = $this->request('POST', '/api/login', json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'pos']));
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];
    }
}
