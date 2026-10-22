<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeTerminalGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot F3 (24/10/2026) : ticket numérique. Bloc ticket complet (nature, lignes, caissier, devise, monnaie rendue, cartes,
 * lien public), réimpression (duplicata, compteur, journal), reçu par courriel (PDF joint, adresse masquée, idempotence),
 * page publique du reçu par jeton signé (HTML, PDF, expiration, révocation, non indexée), réglages.
 */
class PosReceiptApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private ?string $key = null;
    private int $register;
    private int $cap;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeTerminalGateway::reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-recu@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL, pos_approval_refund = NULL, pos_approval_withdrawal = NULL, pos_approval_fund_withdrawal = NULL, pos_approval_drawer_open = false, pos_approval_close_without_count = false, pos_terminal_header_required = false,
            pos_pay_cash = true, pos_pay_declared_card = true, pos_pay_paypal = true, pos_pay_multi = true, pos_discount_enabled = true, pos_receipt_header = 'Merci de votre visite', pos_receipt_footer = 'Retours sous 30 jours', gst_number = '123456789 RT0001', qst_number = '1234567890 TQ0001',
            pos_receipt_email_enabled = true, pos_receipt_qr_enabled = true, pos_receipt_link_days = 90, pos_receipt_qr_print_default = true");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement("UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), terminal_enabled = false, stripe_reader_id = NULL, receipt_prefix = 'C1-'");
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->cap = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $this->db->executeStatement("UPDATE product SET price = 2900, special_price = NULL WHERE code = 'DEMO-CASQUETTE'");
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = 500, price = NULL WHERE id = ?', [$this->cap]);
    }

    public function testFullReceiptReprintEmailAndPublicPage(): void
    {
        $cashier = $this->loginAs(['ROLE_USER_POS']);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]);
        $s = $this->json($this->request('GET', '/api/pos/settings'));
        $this->assertSame(['header' => 'Merci de votre visite', 'footer' => 'Retours sous 30 jours', 'showTaxNumbers' => true, 'emailEnabled' => true, 'qrEnabled' => true, 'linkDays' => 90, 'qrPrintDefault' => true], $s['receipt']);

        // Vente : 2 casquettes, 10 % sur la ligne, 40,00 en espèces (monnaie rendue) + le reste par carte déclarée
        $items = [['productVariantId' => $this->cap, 'quantity' => 2, 'discounts' => [['type' => 'percent', 'value' => 10, 'reasonCode' => 'fidelite']]]];
        $q = $this->json($this->request('POST', '/api/pos/quote', ['items' => $items, 'cashRegisterId' => $this->register]));
        $card = 2000;
        $cashDue = $q['total'] - $card; // 6001 − 2000 ≈ 4001 → arrondi 4000, le client donne 40,00
        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => round($cashDue / 5) * 5 / 100], ['type' => 1, 'amount' => $card / 100]], 'cashRegisterId' => $this->register]), 201);
        $this->assertSame(['sale', false, 0, 'CAD'], [$sale['kind'], $sale['duplicate'], $sale['reprintCount'], $sale['currency']]);
        $this->assertSame(['id' => $cashier->getId(), 'name' => 'Recu Test'], $sale['cashier']);
        $this->assertEquals([[$this->cap, 2, 29, 58, 5.8, 52.2]], array_map(fn ($l) => [$l['productVariantId'], $l['quantity'], $l['unitPrice'], $l['gross'], $l['discountAmount'], $l['total']], $sale['lines']));
        $this->assertSame('declared', array_values(array_filter($sale['payments'], fn ($p) => $p['type'] === 1))[0]['paymentMethodType']);
        $this->assertEquals(0, $sale['change'], 'espèces arrondies : rien à rendre');
        $this->assertStringStartsWith('https://' . MV_TEST_TENANT_HOST . '/api/r/', $sale['receiptUrl'], 'lien public du reçu sous /api/ sur l\'hôte du site');
        $this->assertNotNull($sale['issuedAt']);
        $this->assertSame(['gst' => '123456789 RT0001', 'qst' => '1234567890 TQ0001'], $sale['store']['taxNumbers']);

        // Réimpression par code-barres : duplicata, compteur, journal
        $dup = $this->json($this->request('GET', '/api/pos/receipts?barcode=' . $sale['barcode']));
        $this->assertSame([true, 1, 'sale', $sale['receiptNumber'], $sale['orderId']], [$dup['duplicate'], $dup['reprintCount'], $dup['kind'], $dup['receiptNumber'], $dup['orderId'] ?? $sale['orderId']]);
        $this->assertSame($sale['lines'], $dup['lines']);
        $this->assertSame($sale['payments'], $dup['payments']);
        $this->assertSame(2, $this->json($this->request('GET', '/api/pos/receipts?orderId=' . $sale['orderId']))['reprintCount']);
        $this->assertSame(2, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = 'RECEIPT_REPRINTED' AND payload LIKE ?", ['%' . $sale['barcode'] . '%']));
        $this->assertSame(404, $this->request('GET', '/api/pos/receipts?barcode=IZ-T-' . $this->register . '-999999')->getStatusCode());
        $byNumber = $this->json($this->request('GET', "/api/pos/receipts?kind=ticket&number={$sale['receiptNumber']}&cashRegisterId=$this->register"));
        $this->assertSame($sale['barcode'], $byNumber['barcode']);

        // Reçu par courriel : adresse invalide, envoi (PDF joint, couleurs du profil), journal masqué, rejeu idempotent sans second courriel
        $this->assertSame([422, 'EMAIL_INVALID'], [$this->request('POST', '/api/pos/receipts/email', ['barcode' => $sale['barcode'], 'email' => 'pas-une-adresse'])->getStatusCode(), json_decode($this->request('POST', '/api/pos/receipts/email', ['barcode' => $sale['barcode'], 'email' => 'x'])->getContent(), true)['error']]);
        $this->key = self::uuid();
        $this->client->enableProfiler();
        $sent = $this->json($this->request('POST', '/api/pos/receipts/email', ['barcode' => $sale['barcode'], 'email' => 'jean.client@example.invalid']));
        $this->assertSame(['sent' => true, 'barcode' => $sale['barcode'], 'to' => 'j***@example.invalid'], $sent);
        $mails = $this->mails();
        $this->assertCount(1, $mails, 'un courriel pour l\'envoi ; événements : ' . json_encode($this->lastMailEvents, JSON_UNESCAPED_UNICODE));
        $this->client->enableProfiler();
        $replay = $this->request('POST', '/api/pos/receipts/email', ['barcode' => $sale['barcode'], 'email' => 'jean.client@example.invalid']);
        $this->assertSame('true', $replay->headers->get('Idempotent-Replayed'));
        $this->assertCount(0, $this->mails(), 'rejeu : aucun second courriel ; événements : ' . json_encode($this->lastMailEvents, JSON_UNESCAPED_UNICODE));
        $this->key = null;
        $mail = end($mails);
        $this->assertSame('jean.client@example.invalid', $mail->getTo()[0]->getAddress());
        $this->assertStringContainsString('Reçu n° C1-' . $sale['receiptNumber'], $mail->getSubject());
        $html = (string) $mail->getHtmlBody();
        $this->assertStringContainsString('Casquette', $html);
        $this->assertStringContainsString('N° TPS : 123456789 RT0001', $html);
        $this->assertStringContainsString('Retours sous 30 jours', $html);
        $this->assertStringContainsString($sale['receiptUrl'], $html);
        $pdfs = array_values(array_filter($mail->getAttachments(), fn ($part) => str_starts_with($part->getBody(), '%PDF'))); // + le logo incorporé par le système de courriel
        $this->assertCount(1, $pdfs, 'un reçu PDF joint');
        $this->assertStringContainsString('recu-C1-' . $sale['receiptNumber'] . '.pdf', (string) $pdfs[0]->getPreparedHeaders()->get('Content-Disposition')?->getBodyAsString());
        $journal = json_decode($this->db->fetchOne("SELECT payload FROM pos_audit_log WHERE type = 'RECEIPT_EMAILED' ORDER BY id DESC LIMIT 1"), true);
        $this->assertStringNotContainsString('jean.client', json_encode($journal), 'jamais l\'adresse complète au journal');
        $this->assertStringContainsString('j***@example.invalid', json_encode($journal));
        $this->assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE payload LIKE '%jean.client@%'"), 'aucune adresse conservée');

        // Page publique : HTML non indexé, PDF, jeton altéré, expiré, révoqué
        $url = parse_url($sale['receiptUrl'], PHP_URL_PATH);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . $url);
        $page = $this->client->getResponse();
        $this->assertSame(200, $page->getStatusCode());
        $this->assertSame('noindex, nofollow, noarchive', $page->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $page->headers->get('Cache-Control'));
        $this->assertStringContainsString('Ticket de vente', $page->getContent());
        $this->assertStringContainsString('Casquette', $page->getContent());
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . $url . '?format=pdf');
        $this->assertSame(['application/pdf', '%PDF'], [$this->client->getResponse()->headers->get('Content-Type'), substr((string) $this->client->getResponse()->getContent(), 0, 4)]);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . substr($url, 0, -4) . 'zzzz');
        $this->assertSame(404, $this->client->getResponse()->getStatusCode(), 'signature altérée');
        $links = static::getContainer()->get(\App\Services\PosAuditService\PosReceiptLinkService::class);
        $receipt = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->getRepository(\App\Entity\PosReceipt::class)->findOneBy(['kind' => 'ticket', 'number' => $sale['receiptNumber'], 'cashRegister' => $this->register]);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/r/' . $links->token($receipt, time() - 60));
        $this->assertSame(410, $this->client->getResponse()->getStatusCode(), 'expiré');
        $this->assertStringContainsString('expiré', $this->client->getResponse()->getContent());
        // Révocation (responsable) : l'ancien lien tombe, un nouveau lien est émis
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $revoked = $this->json($this->request('POST', '/api/pos/receipts/revoke-link', ['barcode' => $sale['barcode']]));
        $this->assertTrue($revoked['revoked']);
        $this->assertNotSame($sale['receiptUrl'], $revoked['receiptUrl']);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . $url);
        $this->assertSame(410, $this->client->getResponse()->getStatusCode(), 'lien révoqué');
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . parse_url($revoked['receiptUrl'], PHP_URL_PATH));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), 'nouveau lien valide');
        $this->assertSame('RECEIPT_LINK_REVOKED', $this->db->fetchOne("SELECT type FROM pos_audit_log WHERE type = 'RECEIPT_LINK_REVOKED' ORDER BY id DESC LIMIT 1"));

        // Avoir d'un retour : duplicata de nature return ; réglages désactivés
        $line = $this->json($this->request('GET', '/api/pos/returns/lookup?orderId=' . $sale['orderId']))['lines'][0]['orderItemId'];
        $ret = $this->json($this->request('POST', '/api/pos/returns', ['orderId' => $sale['orderId'], 'lines' => [['orderItemId' => $line, 'quantity' => 1]], 'reasonCode' => 'autre', 'cashRegisterId' => $this->register]), 201);
        $credit = $this->json($this->request('GET', '/api/pos/receipts?barcode=' . $ret['barcode']));
        $this->assertSame(['return', true, $ret['creditNoteNumber']], [$credit['kind'], $credit['duplicate'], $credit['receiptNumber']]);
        $this->assertSame(1, count($credit['lines']));
        $this->db->executeStatement('UPDATE entreprise SET pos_receipt_email_enabled = false, pos_receipt_qr_enabled = false');
        $this->assertSame('RECEIPT_EMAIL_DISABLED', json_decode($this->request('POST', '/api/pos/receipts/email', ['barcode' => $sale['barcode'], 'email' => 'a@example.invalid'])->getContent(), true)['error']);
        $this->assertNull($this->json($this->request('GET', '/api/pos/receipts?barcode=' . $sale['barcode']))['receiptUrl'], 'QR désactivé : pas de lien');
    }

    public function testEmailWithoutSmtpConfigurationIsRefusedExplicitly(): void
    {
        $this->loginAs(['ROLE_USER_POS']);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register]);
        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'paymentMethods' => [['type' => 2, 'amount' => 33.35]], 'cashRegisterId' => $this->register]), 201);
        $saved = $this->db->fetchAllAssociative('SELECT * FROM email_configuration');
        $savedTranslations = $this->db->fetchAllAssociative('SELECT * FROM email_configuration_translation');
        $this->db->executeStatement('DELETE FROM email_configuration_translation');
        $this->db->executeStatement('DELETE FROM email_configuration');
        try {
            $r = $this->request('POST', '/api/pos/receipts/email', ['barcode' => $sale['barcode'], 'email' => 'a@example.invalid']);
            $this->assertSame([503, 'EMAIL_NOT_CONFIGURED'], [$r->getStatusCode(), json_decode($r->getContent(), true)['error']]);
        } finally {
            foreach ($saved as $row) {
                $this->db->insert('email_configuration', $row);
            }
            foreach ($savedTranslations as $row) {
                $this->db->insert('email_configuration_translation', $row);
            }
        }
    }

    // ---------------------------------------------------------------- aides

    /** @return Email[] courriels réellement envoyés lors de la dernière requête (profileur ; les événements de mise en file sont ignorés) */
    private function mails(): array
    {
        $out = [];
        $profile = $this->client->getProfile();
        $this->assertNotFalse($profile, 'profileur actif pour la requête');
        $seen = [];
        foreach ($profile->getCollector('mailer')->getEvents()->getEvents() as $event) {
            $message = $event->getMessage();
            $seen[] = ($event->isQueued() ? 'file:' : 'envoi:') . ($message instanceof Email ? $message->getSubject() : get_class($message));
            if (!$event->isQueued() && $message instanceof Email) {
                $out[] = $message;
            }
        }
        $this->lastMailEvents = $seen;

        return $out;
    }

    private array $lastMailEvents = [];

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
            'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null, 'HTTP_IDEMPOTENCY_KEY' => $this->key,
        ]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function loginAs(array $roles): User
    {
        $email = 'recu-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Recu')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $k = $this->key;
        $this->key = null;
        $this->token = null;
        $login = $this->request('POST', '/api/login', ['username' => $email, 'password' => self::PASSWORD, 'platform' => 'pos']);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];
        $this->key = $k;

        return $user;
    }
}
