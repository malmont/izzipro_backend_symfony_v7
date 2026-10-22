<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\PosAuditService\PosIdempotencyService;
use App\Services\PosAuditService\PosPinService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Chantier C5 partie 1 (22/10/2026) : idempotence des écritures de caisse par l'en-tête Idempotency-Key. Rejeu sans
 * doublon, corps différent refusé, requête en cours, échec puis réessai, écriture validée mais réponse perdue
 * (reconstitution), réservation périmée sans écriture. Sans en-tête : comportement inchangé.
 */
class PosIdempotencyApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private ?string $key = null;
    private ?string $approval = null;
    private int $register;
    private int $variant;
    private array $sale;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-caisse@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement('UPDATE entreprise SET pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL, pos_approval_refund = NULL, pos_approval_withdrawal = NULL, pos_approval_fund_withdrawal = NULL, pos_approval_drawer_open = false, pos_approval_close_without_count = false');
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement('UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register))');
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->variant = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $this->db->executeStatement("UPDATE product SET price = 2900 WHERE code = 'DEMO-CASQUETTE'");
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = 500, price = NULL WHERE id = ?', [$this->variant]);
        $this->sale = ['items' => [['productVariantId' => $this->variant, 'quantity' => 1]], 'paymentMethods' => [['type' => 1, 'amount' => 33.34]], 'cashRegisterId' => $this->register];
    }

    public function testReplayReturnsTheSameResponseWithoutASecondWrite(): void
    {
        $this->loginAs(['ROLE_USER_POS']);
        $this->key = self::uuid();
        $open1 = $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 1000]);
        $open2 = $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 1000]);
        $this->assertSame([201, 201], [$open1->getStatusCode(), $open2->getStatusCode()], $open2->getContent());
        $this->assertSame($open1->getContent(), $open2->getContent(), 'même réponse');
        $this->assertSame([null, 'true'], [$open1->headers->get('Idempotent-Replayed'), $open2->headers->get('Idempotent-Replayed')]);
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM caisse WHERE cash_register_id = ? AND is_open', [$this->register]), 'une seule session');

        $orders = (int) $this->db->fetchOne('SELECT COUNT(*) FROM "order"');
        $this->key = self::uuid();
        $sale1 = $this->request('POST', '/api/order/create-multi-payment', $this->sale);
        $sale2 = $this->request('POST', '/api/order/create-multi-payment', $this->sale);
        $this->assertSame(201, $sale1->getStatusCode(), $sale1->getContent());
        $this->assertSame($sale1->getContent(), $sale2->getContent());
        $this->assertSame('true', $sale2->headers->get('Idempotent-Replayed'));
        $orderId = json_decode($sale1->getContent(), true)['orderId'];
        $this->assertSame($orders + 1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM "order"'), 'une seule vente');
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_receipt WHERE order_id = ?", [$orderId]), 'un seul numéro de ticket');
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = 'SALE' AND payload LIKE ?", ['%"id":' . $orderId . '%']), 'une seule entrée de journal');
        $this->assertSame('done', $this->db->fetchOne('SELECT status FROM pos_idempotency_key WHERE idem_key = ?', [$this->key]));

        $this->key = self::uuid();
        $dep1 = $this->request('POST', '/api/caisse/deposit', ['amount' => 500, 'cashRegisterId' => $this->register]);
        $dep2 = $this->request('POST', '/api/caisse/deposit', ['amount' => 500, 'cashRegisterId' => $this->register]);
        $this->assertSame($dep1->getContent(), $dep2->getContent());
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM transaction_caisse t JOIN caisse c ON c.id = t.caisse_id WHERE c.cash_register_id = ? AND c.is_open AND t.transaction_type_id = 3", [$this->register]), 'un seul dépôt');

        $this->key = self::uuid();
        $drawer1 = $this->request('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => 'Monnaie']);
        $drawer2 = $this->request('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => 'Monnaie']);
        $this->assertSame([201, $drawer1->getContent()], [$drawer2->getStatusCode(), $drawer2->getContent()]);

        $this->key = self::uuid();
        $cancel1 = $this->request('POST', "/api/order/cancel/$orderId", ['paymentMethod' => 1]);
        $cancel2 = $this->request('POST', "/api/order/cancel/$orderId", ['paymentMethod' => 1]);
        $this->assertSame(200, $cancel1->getStatusCode(), $cancel1->getContent());
        $this->assertSame($cancel1->getContent(), $cancel2->getContent());
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_receipt WHERE order_id = ? AND kind = 'credit_note'", [$orderId]), 'un seul avoir');

        $this->key = self::uuid();
        $close1 = $this->request('POST', '/api/caisse/close', ['cashRegisterId' => $this->register, 'countedCash' => 0, 'discrepancyReason' => 'Test']);
        $close2 = $this->request('POST', '/api/caisse/close', ['cashRegisterId' => $this->register, 'countedCash' => 0, 'discrepancyReason' => 'Test']);
        $this->assertSame([200, 'true'], [$close2->getStatusCode(), $close2->headers->get('Idempotent-Replayed')]);
        $this->assertSame($close1->getContent(), $close2->getContent(), 'même rapport Z, pas de second Z');

        // Sans en-tête : comportement inchangé (deux ventes)
        $this->key = null;
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register]);
        $before = (int) $this->db->fetchOne('SELECT COUNT(*) FROM "order"');
        $this->request('POST', '/api/order/create-multi-payment', $this->sale);
        $this->request('POST', '/api/order/create-multi-payment', $this->sale);
        $this->assertSame($before + 2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM "order"'));
    }

    public function testKeyReuseInProgressAndInvalidKeyAreRefused(): void
    {
        $this->loginAs(['ROLE_USER_POS']);
        $this->key = 'pas-un-uuid';
        $invalid = $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register]);
        $this->assertSame([422, 'IDEMPOTENCY_KEY_INVALID'], [$invalid->getStatusCode(), json_decode($invalid->getContent(), true)['error']]);

        $this->key = self::uuid();
        $this->assertSame(201, $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register])->getStatusCode());
        $reused = $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 100]);
        $this->assertSame([422, 'IDEMPOTENCY_KEY_REUSED'], [$reused->getStatusCode(), json_decode($reused->getContent(), true)['error']], 'même clé, corps différent');
        $otherRoute = $this->request('POST', '/api/caisse/deposit', ['cashRegisterId' => $this->register]);
        $this->assertSame('IDEMPOTENCY_KEY_REUSED', json_decode($otherRoute->getContent(), true)['error'], 'même clé, autre route');
        [$cashier, $cashierEmail] = [$this->token, $this->email];
        $this->loginAs(['ROLE_USER_POS']);
        $otherUser = $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register]);
        $this->assertSame('IDEMPOTENCY_KEY_REUSED', json_decode($otherUser->getContent(), true)['error'], 'même clé, autre utilisateur');
        [$this->token, $this->email] = [$cashier, $cashierEmail];
        $this->assertSame('true', $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register])->headers->get('Idempotent-Replayed'), 'et l\'auteur rejoue toujours');

        // Réservation récente encore en cours (simulée) : 409 avec Retry-After
        $this->key = self::uuid();
        $this->reserve($this->key, 'caisse_deposit /api/caisse/deposit', ['amount' => 100, 'cashRegisterId' => $this->register], '-5 seconds');
        $busy = $this->request('POST', '/api/caisse/deposit', ['amount' => 100, 'cashRegisterId' => $this->register]);
        $this->assertSame([409, 'IDEMPOTENCY_IN_PROGRESS', '2'], [$busy->getStatusCode(), json_decode($busy->getContent(), true)['error'], $busy->headers->get('Retry-After')]);
    }

    public function testAFailedWriteLeavesTheKeyReusable(): void
    {
        $this->db->executeStatement('UPDATE entreprise SET pos_approval_withdrawal = 0');
        $manager = $this->user(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        static::getContainer()->get(PosPinService::class)->set($manager, '8642');
        $terminal = $this->terminal();
        $this->loginAs(['ROLE_USER_POS']);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 5000]);

        $this->key = self::uuid();
        $refused = $this->request('POST', '/api/caisse/withdraw', ['amount' => 1000, 'cashRegisterId' => $this->register], $terminal);
        $this->assertSame([403, 'APPROVAL_REQUIRED'], [$refused->getStatusCode(), json_decode($refused->getContent(), true)['error']]);
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM pos_idempotency_key WHERE idem_key = ?', [$this->key]), 'refus : réservation effacée');

        $saved = $this->key;
        $this->key = null;
        $granted = $this->request('POST', '/api/pos/approvals', ['action' => 'withdrawal', 'managerId' => $manager->getId(), 'pin' => '8642', 'context' => ['amount' => 1000, 'cashRegisterId' => $this->register]], $terminal);
        $this->assertSame(201, $granted->getStatusCode(), $granted->getContent());
        $this->approval = json_decode($granted->getContent(), true)['approvalToken'];
        $this->key = $saved;
        $done = $this->request('POST', '/api/caisse/withdraw', ['amount' => 1000, 'cashRegisterId' => $this->register], $terminal);
        $this->assertSame(201, $done->getStatusCode(), 'même clé après l\'échec : ' . $done->getContent());
        $this->approval = null;
        $again = $this->request('POST', '/api/caisse/withdraw', ['amount' => 1000, 'cashRegisterId' => $this->register], $terminal);
        $this->assertSame([201, 'true', $done->getContent()], [$again->getStatusCode(), $again->headers->get('Idempotent-Replayed'), $again->getContent()], 'rejoué sans validation ni second retrait');
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM transaction_caisse t JOIN caisse c ON c.id = t.caisse_id WHERE c.cash_register_id = ? AND c.is_open AND t.transaction_type_id = 6", [$this->register]));
    }

    public function testLostResponseIsRebuiltAndAStaleReservationWithoutWriteIsTakenOver(): void
    {
        $this->loginAs(['ROLE_USER_POS']);
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register]);
        $orders = (int) $this->db->fetchOne('SELECT COUNT(*) FROM "order"');

        // Écriture validée, réponse et marquage de succès perdus (coupure) : la clé est restée « committed »
        $this->key = self::uuid();
        $first = $this->request('POST', '/api/order/create-multi-payment', $this->sale);
        $this->assertSame(201, $first->getStatusCode(), $first->getContent());
        $this->db->executeStatement("UPDATE pos_idempotency_key SET status = 'committed', response_status = NULL, response_body = NULL WHERE idem_key = ?", [$this->key]);
        $this->assertSame(['committed', 'sale'], array_values($this->db->fetchAssociative('SELECT status, subject_type FROM pos_idempotency_key WHERE idem_key = ?', [$this->key])));
        $retry = $this->request('POST', '/api/order/create-multi-payment', $this->sale);
        $this->assertSame([201, 'true'], [$retry->getStatusCode(), $retry->headers->get('Idempotent-Replayed')], $retry->getContent());
        $this->assertSame(json_decode($first->getContent(), true), json_decode($retry->getContent(), true), 'réponse reconstituée identique');
        $this->assertSame($orders + 1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM "order"'), 'aucune seconde vente');
        $this->assertSame('done', $this->db->fetchOne('SELECT status FROM pos_idempotency_key WHERE idem_key = ?', [$this->key]));

        // Même cas pour un dépôt (réponse gardée dans la transaction) et une fermeture
        $this->key = self::uuid();
        $dep = $this->request('POST', '/api/caisse/deposit', ['amount' => 700, 'cashRegisterId' => $this->register]);
        $this->db->executeStatement("UPDATE pos_idempotency_key SET status = 'committed', response_body = NULL WHERE idem_key = ?", [$this->key]);
        $this->db->executeStatement("UPDATE pos_idempotency_key SET subject_type = 'session_open', subject_id = (SELECT id FROM caisse WHERE cash_register_id = ? AND is_open) WHERE idem_key = ?", [$this->register, $this->key]);
        $this->assertSame('true', $this->request('POST', '/api/caisse/deposit', ['amount' => 700, 'cashRegisterId' => $this->register])->headers->get('Idempotent-Replayed'));
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM transaction_caisse t JOIN caisse c ON c.id = t.caisse_id WHERE c.cash_register_id = ? AND c.is_open AND t.transaction_type_id = 3 AND t.amount = 700", [$this->register]), 'aucun second dépôt');

        // Réservation périmée (> 60 s) SANS écriture : reprise, la requête s'exécute une seule fois
        $this->key = self::uuid();
        $this->reserve($this->key, 'order_create_multi_payment /api/order/create-multi-payment', $this->sale, '-3 minutes');
        $taken = $this->request('POST', '/api/order/create-multi-payment', $this->sale);
        $this->assertSame([201, null], [$taken->getStatusCode(), $taken->headers->get('Idempotent-Replayed')], $taken->getContent());
        $this->assertSame($orders + 2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM "order"'), 'la vente de la réservation périmée n\'existait pas : créée une fois');
        $this->assertSame('true', $this->request('POST', '/api/order/create-multi-payment', $this->sale)->headers->get('Idempotent-Replayed'));
        $this->assertSame($orders + 2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM "order"'));
    }

    /** Réservation simulée (comme si une requête identique était partie) */
    private function reserve(string $key, string $route, array $body, string $age): void
    {
        $userId = (int) $this->db->fetchOne('SELECT id FROM "user" WHERE email = ?', [$this->email]);
        $this->db->insert('pos_idempotency_key', ['idem_key' => $key, 'user_id' => $userId, 'route' => $route, 'body_hash' => PosIdempotencyService::bodyHash(json_encode($body)),
            'status' => 'in_progress', 'created_at' => (new \DateTimeImmutable($age))->format('Y-m-d H:i:s')]);
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private function terminal(): string
    {
        $saved = $this->token;
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']);
        $key = json_decode($this->request('POST', '/api/app-keys', ['app' => 'pos', 'label' => 'Tablette idempotence', 'sendEmail' => false])->getContent(), true)['key'];
        $this->token = $saved;
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/app/activate', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['key' => $key, 'app' => 'pos']));

        return json_decode($this->client->getResponse()->getContent(), true)['terminal']['token'];
    }

    private string $email = '';

    private function request(string $method, string $path, ?array $body = null, ?string $terminal = null): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null,
            'HTTP_IDEMPOTENCY_KEY' => $this->key,
            'HTTP_X_POS_TERMINAL' => $terminal,
            'HTTP_X_POS_APPROVAL' => $this->approval,
        ]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function loginAs(array $roles): User
    {
        $user = $this->user($roles);
        $this->email = $user->getEmail();
        $saved = $this->key;
        $this->key = null;
        $this->token = null;
        $login = $this->request('POST', '/api/login', ['username' => $user->getEmail(), 'password' => self::PASSWORD, 'platform' => 'pos']);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];
        $this->key = $saved;

        return $user;
    }

    private function user(array $roles): User
    {
        $email = 'idem-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Idem')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $user;
    }
}
