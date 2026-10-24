<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\ProductVariant;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\PosAuditService\PosPinService;
use App\Services\RetentionService\RetentionViolationException;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeTerminalGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Lot A1/A4 (30/10/2026) : instantanés de lignes, garde de conservation (suppression refusée, modifications observées ou bloquées), archive annuelle, documents en français */
class PosRetentionArchiveApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private ?string $terminal = null;
    private string $currentEmail = '';
    private int $register;
    private int $cap;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeTerminalGateway::reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-conservation@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL, pos_approval_refund = NULL, pos_approval_withdrawal = NULL, pos_approval_fund_withdrawal = NULL, pos_approval_drawer_open = false, pos_approval_close_without_count = false, pos_terminal_header_required = false,
            pos_pay_cash = true, pos_pay_declared_card = true, pos_pay_paypal = true, pos_pay_multi = true, pos_return_max_days = NULL, pos_return_without_receipt = false, pos_discount_enabled = true, pos_custom_item_enabled = false, pos_price_override_enabled = false, pos_offline_enabled = false, pos_gift_card_enabled = false,
            data_retention_years = NULL, retention_write_mode = 'observe', pos_gift_card_code_attempts = 10, pos_timezone = NULL");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement("UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), terminal_enabled = false, stripe_reader_id = NULL, receipt_prefix = NULL");
        $this->db->executeStatement('UPDATE product SET is_pos = true');
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->cap = $this->variant('DEMO-CASQUETTE', 2900);
    }

    public function testSnapshotsRetentionGuardArchiveAndFrenchDocuments(): void
    {
        $manager = $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        static::getContainer()->get(PosPinService::class)->set($manager, '1122');
        $this->terminal = $this->posTerminal();
        $this->request('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 10000]);
        $s = $this->json($this->request('GET', '/api/pos/settings'));
        $this->assertSame(['years' => 6, 'legalYears' => 6, 'country' => 'CA', 'source' => 'country', 'writeMode' => 'observe', 'purge' => 'none', 'fiscalYear' => 'calendar'], $s['retention'], 'Canada : 6 ans après la fin de l\'année, aucune purge');
        $this->db->executeStatement('UPDATE entreprise SET data_retention_years = 3');
        $this->assertSame([6, 'country'], [$this->json($this->request('GET', '/api/pos/settings'))['retention']['years'], $this->json($this->request('GET', '/api/pos/settings'))['retention']['source']], 'un réglage sous le plancher légal est ignoré au profit du plancher');
        $this->db->executeStatement('UPDATE entreprise SET data_retention_years = 8');
        $this->assertSame([8, 'setting'], [$this->json($this->request('GET', '/api/pos/settings'))['retention']['years'], $this->json($this->request('GET', '/api/pos/settings'))['retention']['source']]);
        $this->db->executeStatement('UPDATE entreprise SET data_retention_years = NULL');
        $validator = static::getContainer()->get(\Symfony\Component\Validator\Validator\ValidatorInterface::class);
        $e = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->getRepository(\App\Entity\Entreprise::class)->findOneBy([]);
        $violations = array_values(array_filter(iterator_to_array($validator->validate($e->setDataRetentionYears(3))), fn ($v) => $v->getPropertyPath() === 'dataRetentionYears'));
        $this->assertCount(1, $violations, 'EasyAdmin : refus sous le plancher légal');
        $this->assertStringContainsString('plancher légal du pays (6 ans)', $violations[0]->getMessage());
        $e->setDataRetentionYears(null);

        // Instantanés : libellés figés à la vente ; renommer le produit ne change ni le ticket ni les listes
        $cashier = $this->loginAs(['ROLE_USER_POS']);
        $sale = $this->json($this->request('POST', '/api/order/create-multi-payment', ['items' => [['productVariantId' => $this->cap, 'quantity' => 1]], 'paymentMethods' => [['type' => 2, 'amount' => 33.35]], 'cashRegisterId' => $this->register]), 201);
        $row = $this->db->fetchAssociative('SELECT product_name, product_code, barcode, variant_label FROM order_items WHERE order_associated_id = ?', [$sale['orderId']]);
        $name = $this->db->fetchOne('SELECT name FROM product WHERE code = ?', ['DEMO-CASQUETTE']);
        $this->assertSame([$name, 'DEMO-CASQUETTE'], [$row['product_name'], $row['product_code']], 'instantané rempli à la vente');
        $this->db->executeStatement("UPDATE product SET name = 'Casquette renommée' WHERE code = 'DEMO-CASQUETTE'");
        $this->assertSame($name, $this->json($this->request('GET', '/api/pos/receipts?barcode=' . $sale['barcode']))['lines'][0]['name'], 'ticket : libellé d\'origine');
        $orders = $this->json($this->request('GET', '/api/orders?orderSource=2&days=2'));
        $this->assertSame($name, array_values(array_filter($orders, fn ($o) => $o['id'] === $sale['orderId']))[0]['orderItems'][0]['productVariantName'], 'liste : libellé d\'origine');
        $this->db->executeStatement('UPDATE product SET name = ? WHERE code = ?', [$name, 'DEMO-CASQUETTE']);

        // Garde de conservation : suppression refusée (vente, ligne, utilisateur ayant vendu, variante vendue) avec messages clairs
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $em->clear();
        $order = $em->find(Order::class, $sale['orderId']);
        $until = ((int) (new \DateTimeImmutable('now', new \DateTimeZone('America/Toronto')))->format('Y') + 6) . '-12-31';
        $this->assertRefused(fn () => $em->remove($order), 'RETENTION_LOCKED', 'Commande #' . $sale['orderId'], $em);
        $this->assertRefused(fn () => $em->remove($em->getRepository(OrderItems::class)->findOneBy(['orderAssociated' => $order])), 'RETENTION_LOCKED', 'Ligne de commande', $em);
        $this->assertStringContainsString(substr($until, 0, 4), $this->lastRefusal, 'fin de conservation : 31 décembre de l\'année + 6');
        $this->assertRefused(fn () => $em->remove($em->find(User::class, $cashier->getId())), 'RETENTION_LOCKED', 'a réalisé des ventes', $em);
        $this->assertRefused(fn () => $em->remove($em->find(ProductVariant::class, $this->cap)), 'RETENTION_LOCKED', 'figure sur des lignes de vente', $em);
        // Commandes web : payée → refusée ; jamais payée (panier abandonné) → supprimable
        $cols = $this->db->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_name = 'order' AND column_name NOT IN ('id', 'reference', 'order_source_id', 'status_id')");
        $list = implode(', ', array_map(fn ($c) => '"' . $c . '"', $cols));
        $seq = (string) $this->db->fetchOne("SELECT sequence_name FROM information_schema.sequences WHERE sequence_name ILIKE 'order%' AND sequence_name NOT ILIKE 'order_items%' AND sequence_name NOT ILIKE 'order_tax%' AND sequence_name NOT ILIKE 'order_source%' AND sequence_name NOT ILIKE 'order_type%' ORDER BY 1 LIMIT 1");
        $webPaid = (int) $this->db->fetchOne("INSERT INTO \"order\" (id, reference, order_source_id, status_id, $list) SELECT nextval('$seq'), 'REF#web-paid-' || md5(random()::text), 1, 3, $list FROM \"order\" WHERE id = ? RETURNING id", [$sale['orderId']]);
        $webUnpaid = (int) $this->db->fetchOne("INSERT INTO \"order\" (id, reference, order_source_id, status_id, $list) SELECT nextval('$seq'), 'REF#web-unpaid-' || md5(random()::text), 1, 1, $list FROM \"order\" WHERE id = ? RETURNING id", [$sale['orderId']]);
        $em->clear();
        $this->assertRefused(fn () => $em->remove($em->find(Order::class, $webPaid)), 'RETENTION_LOCKED', 'Commande #' . $webPaid, $em);
        $em->remove($em->find(Order::class, $webUnpaid));
        $em->flush();
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM "order" WHERE id = ?', [$webUnpaid]), 'commande web jamais payée : supprimable');

        // Modifications : observées (journal RETENTION_WRITE_OBSERVED avec entité, champs, origine, utilisateur) puis bloquées
        $em->clear();
        $order = $em->find(Order::class, $sale['orderId']);
        $order->setStatus($em->getReference(\App\Entity\StatusCommande::class, 3));
        $em->flush();
        $this->assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = 'RETENTION_WRITE_OBSERVED' AND payload LIKE ?", ['%"id":' . $sale['orderId'] . ',%']), 'champ permis (statut) : rien à journaliser');
        $order->setReference($order->getReference() . '-X');
        $em->flush();
        $observed = json_decode((string) $this->db->fetchOne("SELECT payload FROM pos_audit_log WHERE type = 'RETENTION_WRITE_OBSERVED' ORDER BY id DESC LIMIT 1"), true);
        $d = $observed['data'] ?? $observed;
        $this->assertSame([Order::class, $sale['orderId'], ['reference']], [$d['entity'], $d['id'], $d['fields']], 'modification observée');
        $this->assertStringContainsString('console', $d['origin'], 'origine : hors requête HTTP, console');
        $this->db->executeStatement("UPDATE entreprise SET retention_write_mode = 'block'");
        $order->setReference(str_replace('-X', '', $order->getReference()));
        $this->assertRefused(fn () => null, 'RETENTION_LOCKED', 'modification refusée (champs : reference)', $em);
        $this->db->executeStatement("UPDATE entreprise SET retention_write_mode = 'observe'");
        $em->clear();

        // Archive annuelle en flux : volume (300 ventes copiées), ZIP (JSON, PDF, SHA256SUMS), empreinte, journal chaîné vérifié ; caissier refusé
        $this->db->executeStatement("CREATE TEMP TABLE tmp_new_orders AS SELECT nextval('$seq') AS id FROM generate_series(1, 300)");
        $this->db->executeStatement("INSERT INTO \"order\" (id, reference, order_source_id, status_id, $list) SELECT n.id, 'REF#vol-' || n.id, 2, 3, $list FROM \"order\" o CROSS JOIN tmp_new_orders n WHERE o.id = " . (int) $sale['orderId']);
        $icols = $this->db->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_name = 'order_items' AND column_name NOT IN ('id', 'order_associated_id')");
        $ilist = implode(', ', array_map(fn ($c) => '"' . $c . '"', $icols));
        $iseq = (string) $this->db->fetchOne("SELECT sequence_name FROM information_schema.sequences WHERE sequence_name ILIKE 'order_items%' ORDER BY 1 LIMIT 1");
        $this->db->executeStatement("INSERT INTO order_items (id, order_associated_id, $ilist) SELECT nextval('$iseq'), n.id, $ilist FROM order_items i CROSS JOIN tmp_new_orders n WHERE i.order_associated_id = " . (int) $sale['orderId']);
        $this->assertSame(403, $this->request('GET', '/api/pos/archive/' . date('Y'))->getStatusCode(), 'caissier : archive refusée');
        $this->loginAs(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $zipResponse = $this->request('GET', '/api/pos/archive/' . date('Y'));
        $this->assertSame(200, $zipResponse->getStatusCode(), substr((string) $zipResponse->getContent(), 0, 300));
        $this->assertSame(['application/zip', '1', 64], [$zipResponse->headers->get('Content-Type'), $zipResponse->headers->get('X-Archive-Chain-Valid'), strlen((string) $zipResponse->headers->get('X-Archive-Sha256'))], 'téléchargement : en-têtes d\'empreinte et de chaîne');
        $this->assertStringContainsString('attachment; filename=', (string) $zipResponse->headers->get('Content-Disposition'));
        // Contenu vérifié sur une archive produite par le service (le fichier du téléchargement est supprimé après envoi)
        $managerUser = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->getRepository(User::class)->findOneBy(['email' => $this->currentEmail]);
        $a = static::getContainer()->get(\App\Services\ArchiveService\PosArchiveService::class)->build($managerUser, (int) date('Y'));
        $this->assertSame(hash_file('sha256', $a['path']), $a['sha256'], 'empreinte de contrôle');
        $this->assertTrue($a['chainValid']);
        $tmp = $a['path'];
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tmp));
        $json = json_decode((string) $zip->getFromName('archive.json'), true);
        $sums = (string) $zip->getFromName('SHA256SUMS');
        $this->assertSame(['archive.json', 'archive.pdf', 'SHA256SUMS', 'README.txt'], array_map(fn ($i) => $zip->getNameIndex($i), range(0, 3)));
        $this->assertStringContainsString(hash('sha256', (string) $zip->getFromName('archive.json')) . '  archive.json', $sums);
        $this->assertStringStartsWith('%PDF', (string) $zip->getFromName('archive.pdf'));
        $this->assertSame(['izzipro-pos-archive', (int) date('Y'), true], [$json['format'], $json['year'], $json['journal']['chain']['valid']]);
        $this->assertGreaterThanOrEqual(301, $json['counts']['orders'], '300 ventes copiées + la vente réelle');
        $this->assertSame($json['counts']['orders'], count($json['orders']));
        $this->assertGreaterThan(0, $json['counts']['journal']);
        $mine = array_values(array_filter($json['orders'], fn ($o) => (int) $o['id'] === (int) $sale['orderId'])); // base de test partagée : chercher SA vente, pas la première de l'année
        $this->assertCount(1, $mine, 'la vente du test est archivée');
        $this->assertSame($name, $mine[0]['items'][0]['product_name'], 'lignes avec instantanés');
        $zip->close();
        unlink($tmp);
        $arch = json_decode((string) $this->db->fetchOne("SELECT payload FROM pos_audit_log WHERE type = 'ARCHIVE_EXPORTED' ORDER BY id DESC LIMIT 1"), true);
        $this->assertSame($a['sha256'], ($arch['data'] ?? $arch)['sha256'], 'journal ARCHIVE_EXPORTED avec empreinte');
        $this->assertSame(2, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = 'ARCHIVE_EXPORTED' AND payload LIKE ?", ['%"year":' . date('Y') . ',%']), 'deux archives journalisées (téléchargement et service)');

        // A4 : documents en français (ticket, page publique, PDF, courriel) ; natures traduites
        $twig = static::getContainer()->get('twig');
        $english = ['Receipt', 'Thank you', 'Cashier', 'Change', 'Subtotal', 'Total due', 'Duplicate', 'Tax', 'Payment'];
        foreach (['sale' => 'Ticket de vente', 'return' => 'Avoir (retour)', 'gift_card' => 'Ticket de carte-cadeau', 'gift_card_cancellation' => 'Avoir (carte-cadeau annulée)', 'exchange' => 'Ticket d\'échange', 'cancellation' => 'Avoir (annulation)'] as $kind => $label) {
            $html = $twig->render('pos/_receipt_body.html.twig', ['receipt' => ['kind' => $kind] + $sale, 'profile' => null, 'duplicate' => $kind === 'sale']);
            $text = html_entity_decode(trim(preg_replace('/\s+/', ' ', strip_tags($html))), ENT_QUOTES | ENT_HTML5);
            $this->assertStringContainsString($label, $text, "nature $kind en français");
            foreach ($english as $word) {
                $this->assertDoesNotMatchRegularExpression('/\b' . preg_quote($word, '/') . '\b/', $text, "mot anglais « $word » dans le ticket $kind");
            }
        }
        $this->assertStringContainsString('DUPLICATA', strip_tags($twig->render('pos/_receipt_body.html.twig', ['receipt' => $sale, 'profile' => null, 'duplicate' => true])));
        $public = $this->client->request('GET', $sale['receiptUrl'], [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Télécharger en PDF', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('lang="fr"', $this->client->getResponse()->getContent());
        $this->db->executeStatement('DROP TABLE IF EXISTS tmp_new_orders');
    }

    private string $lastRefusal = '';

    /** Exécute $do puis flush : attend RetentionViolationException avec le code et un fragment de message ; l'EM est nettoyé ensuite */
    private function assertRefused(callable $do, string $code, string $fragment, $em): void
    {
        try {
            $do();
            $em->flush();
            $this->fail("Refus attendu ($fragment)");
        } catch (RetentionViolationException $e) {
            $this->lastRefusal = $e->getMessage();
            $this->assertSame($code, $e->errorCode);
            $this->assertStringContainsString($fragment, $e->getMessage());
        }
        $em->clear();
    }

    // ---------------------------------------------------------------- aides

    private function variant(string $code, int $price): int
    {
        $id = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = ? ORDER BY v.id LIMIT 1", [$code]);
        $this->db->executeStatement('UPDATE product SET price = ?, special_price = NULL WHERE code = ?', [$price, $code]);
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = 500, price = NULL WHERE id = ?', [$id]);

        return $id;
    }

    private function posTerminal(): string
    {
        $saved = $this->token;
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']);
        $key = $this->json($this->request('POST', '/api/app-keys', ['app' => 'pos', 'label' => 'Tablette conservation', 'sendEmail' => false]), 201)['key'];
        $this->token = $saved;
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/app/activate', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['key' => $key, 'app' => 'pos']));

        return json_decode($this->client->getResponse()->getContent(), true)['terminal']['token'];
    }

    private function json(Response $response, int $expected = 200): array
    {
        $this->assertSame($expected, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent(), true);
    }

    private function request(string $method, string $path, ?array $body = null): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null, 'HTTP_X_POS_TERMINAL' => $this->terminal,
        ]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function user(array $roles): User
    {
        $email = 'conservation-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Conservation')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $user;
    }

    private function loginAs(array $roles): User
    {
        $user = $this->user($roles);
        $this->token = null;
        $login = $this->request('POST', '/api/login', ['username' => $user->getEmail(), 'password' => self::PASSWORD, 'platform' => 'pos']);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];
        $this->currentEmail = (string) $user->getEmail();

        return $user;
    }
}
