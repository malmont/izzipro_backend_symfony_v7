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
 * Garde des avoirs (01/11/2026, anomalie de recette) : un avoir (commande de retour F2) ne s'annule pas (409 ORDER_NOT_REFUNDABLE, rien ne sort
 * de la caisse), ne se consulte ni ne se retourne ni ne s'échange (409 ORDER_NOT_RETURNABLE) ; une vente déjà annulée ne s'annule pas deux fois ;
 * l'annulation d'une vente normale et le code-barres d'un avoir (→ vente d'origine) restent inchangés.
 */
class PosCreditNoteGuardApiTest extends WebTestCase
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
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-avoir@example.invalid', false, false);
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', pos_cash_rounding_step = NULL, pos_approval_refund = NULL, pos_approval_return_mode = 'refund', pos_approval_return = NULL, pos_return_cash_for_card_sale = true, pos_return_max_days = NULL, pos_return_without_receipt = false, pos_gift_card_enabled = true");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement('UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), receipt_prefix = NULL');
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->cap = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $this->db->executeStatement("UPDATE product SET price = 2900, special_price = NULL WHERE code = 'DEMO-CASQUETTE'");
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = 100, price = NULL WHERE id = ?', [$this->cap]);
    }

    public function testACreditNoteCanNeitherBeCanceledNorReturnedAndNothingLeavesTheRegister(): void
    {
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]);
        $items = [['productVariantId' => $this->cap, 'quantity' => 2]];
        $error = fn (Response $r) => [$r->getStatusCode(), json_decode($r->getContent(), true)['error'] ?? null];
        $state = fn () => ['stock' => (int) $this->db->fetchOne('SELECT stock_quantity FROM product_variant WHERE id = ?', [$this->cap]), 'expectedCash' => $this->json($this->request('GET', "/api/caisse/report/x?cashRegisterId=$this->register"))['expectedCash'],
            'payments' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM payments p JOIN "order" o ON o.id = p.order_payment_id WHERE o.order_source_id = 2'), 'receipts' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM pos_receipt')];

        // Vente de deux casquettes (66,68 $), retour d'une unité : avoir n° X de −33,34 $
        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => 66.70]], 'cashRegisterId' => $this->register]), 201);
        $look = $this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $sale['orderId']));
        $ret = $this->json($this->request('POST', '/api/pos/returns', ['orderId' => $sale['orderId'], 'lines' => [['orderItemId' => $look['lines'][0]['orderItemId'], 'quantity' => 1]], 'reasonCode' => 'autre', 'refund' => ['method' => 'cash'], 'cashRegisterId' => $this->register]), 201);
        $avoir = $ret['returnOrderId'];
        $this->assertContains($ret['amount'], [3334, 3335], 'avoir d\'environ 33,34 $ (répartition des taxes et de l\'arrondi)');
        $this->assertSame(2, (int) $this->db->fetchOne('SELECT order_type_id FROM "order" WHERE id = ?', [$avoir]), 'commande de type retour');
        $this->assertLessThan(0, (int) $this->db->fetchOne('SELECT total_amount FROM "order" WHERE id = ?', [$avoir]), 'montant négatif en base');
        $before = $state();

        // Consultation, retour, échange sur l'avoir : refusés
        $this->assertSame([409, 'ORDER_NOT_RETURNABLE'], $error($this->request('GET', "/api/pos/returns/lookup?orderId=$avoir")), 'on ne retourne pas un retour');
        $this->assertSame($sale['orderId'], json_decode($this->request('GET', "/api/pos/returns/lookup?orderId=$avoir")->getContent(), true)['originalOrderId']);
        $avoirItem = (int) $this->db->fetchOne('SELECT id FROM order_items WHERE order_associated_id = ?', [$avoir]);
        $this->assertSame([409, 'ORDER_NOT_RETURNABLE'], $error($this->request('POST', '/api/pos/returns', ['orderId' => $avoir, 'lines' => [['orderItemId' => $avoirItem, 'quantity' => 1]], 'reasonCode' => 'autre', 'cashRegisterId' => $this->register])));
        $this->assertSame([409, 'ORDER_NOT_RETURNABLE'], $error($this->request('POST', '/api/pos/exchanges/quote', ['return' => ['orderId' => $avoir, 'lines' => [['orderItemId' => $avoirItem, 'quantity' => 1]], 'reasonCode' => 'autre'], 'sale' => ['items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'cashRegisterId' => $this->register]])));
        $this->assertSame([409, 'ORDER_NOT_RETURNABLE'], $error($this->request('POST', '/api/pos/exchanges', ['return' => ['orderId' => $avoir, 'lines' => [['orderItemId' => $avoirItem, 'quantity' => 1]], 'reasonCode' => 'autre'], 'sale' => ['items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'paymentMethods' => [['type' => 2, 'amount' => 0]], 'cashRegisterId' => $this->register]])));
        // Le code-barres de l'avoir renvoie à la vente d'origine, encore retournable pour l'unité restante
        $byBarcode = $this->json($this->request('GET', '/api/pos/returns/lookup?barcode=' . $ret['barcode']));
        $this->assertSame([$sale['orderId'], true, 1], [$byBarcode['orderId'], $byBarcode['eligible']['allowed'], $byBarcode['lines'][0]['returnableQuantity']]);

        // Annulation de l'avoir : refusée, rien ne bouge (stock, espèces, paiements, avoirs numérotés, statut)
        $cancel = $this->request('POST', "/api/order/cancel/$avoir", ['paymentMethod' => 2, 'cashRegisterId' => $this->register]);
        $this->assertSame([409, 'ORDER_NOT_REFUNDABLE'], $error($cancel));
        $this->assertStringContainsString('avoir', json_decode($cancel->getContent(), true)['message']);
        $this->assertSame($before, $state(), 'rien ne sort de la caisse');
        $this->assertSame(3, (int) $this->db->fetchOne('SELECT status_id FROM "order" WHERE id = ?', [$avoir]), 'avoir toujours « complété »');
        $this->assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = 'REFUND' AND payload LIKE '%\"kind\":\"cancellation\"%' AND payload LIKE ?", ['%"subject":{"type":"order","id":' . $avoir . '}%']), 'aucune annulation journalisée sur l\'avoir (seul le retour lui-même l\'est)');

        // Annulation de la vente d'origine (unité restante) : inchangée, puis seconde annulation refusée
        $ok = $this->request('POST', "/api/order/cancel/{$sale['orderId']}", ['paymentMethod' => 2, 'cashRegisterId' => $this->register]);
        $this->assertSame(200, $ok->getStatusCode(), $ok->getContent());
        $this->assertSame(7, (int) $this->db->fetchOne('SELECT status_id FROM "order" WHERE id = ?', [$sale['orderId']]));
        $afterCancel = $state();
        $again = $this->request('POST', "/api/order/cancel/{$sale['orderId']}", ['paymentMethod' => 2, 'cashRegisterId' => $this->register]);
        $this->assertSame([409, 'ORDER_NOT_REFUNDABLE'], $error($again), 'déjà annulée');
        $this->assertSame($afterCancel, $state(), 'seconde annulation sans effet');
        // Le code-barres de l'avoir d'annulation renvoie à la vente annulée : consultable, non retournable
        $creditNote = json_decode($ok->getContent(), true);
        $canceledLook = $this->json($this->request('GET', '/api/pos/returns/lookup?barcode=IZ-A-' . $this->register . '-' . $creditNote['creditNoteNumber']));
        $this->assertSame([false, 'ORDER_CANCELED'], [$canceledLook['eligible']['allowed'], $canceledLook['eligible']['reason']]);

        // Vente d'une carte-cadeau : annulation par la route des cartes (inchangé), pas de retour
        $card = $this->json($this->request('POST', '/api/pos/gift-cards', ['amount' => 2500, 'paymentMethods' => [['type' => 2, 'amount' => 25]], 'cashRegisterId' => $this->register]), 201);
        $this->assertSame([409, 'GIFT_CARD_ORDER'], $error($this->request('POST', "/api/order/cancel/{$card['orderId']}", ['paymentMethod' => 2, 'cashRegisterId' => $this->register])));
        $this->assertSame([409, 'ORDER_NOT_RETURNABLE'], $error($this->request('GET', "/api/pos/returns/lookup?orderId={$card['orderId']}")));
    }

    private function json(Response $response, int $expected = 200): array
    {
        $this->assertSame($expected, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent(), true);
    }

    private function request(string $method, string $path, ?array $body = null): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter(['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function loginAs(array $roles): User
    {
        $email = 'avoir-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Avoir')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
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
