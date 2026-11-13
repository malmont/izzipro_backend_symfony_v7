<?php

namespace App\Tests\Functional\Support;

use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\PlatformModeService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Support\Services\BusinessClock;
use App\Support\Services\SupportRetentionService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Module Assistance, lot 1 (12/11/2026, contrat validé par l'application) : cycle d'une demande (création par l'agent, liste et filtres,
 * transitions et 409, réponse au client en journal puis par courriel dans sa langue, note interne, message du client consigné, fermeture et
 * réouverture), pièces jointes vérifiées par leur contenu et stockées en privé, délai en heures ouvrées, résumé de commande en lecture SANS
 * prix de la gestion, réglages (lecture par l'agent, écriture par l'administrateur), conservation 3 ans puis anonymisation, garde du module,
 * connexion d'un compte Assistance, cheminement du site.
 */
class SupportApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private string $support = '';
    private string $admin = '';
    private User $supportUser;
    private ?string $token = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        $this->clean();
        $this->supportUser = $this->user(['ROLE_SUPPORT']);
        $jwt = static::getContainer()->get('lexik_jwt_authentication.jwt_manager');
        $this->support = $jwt->create($this->supportUser);
        $this->admin = $jwt->create($this->user(['ROLE_ADMIN']));
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(PlatformModeService::class)->setMode('demo', 'test');
        $this->clean();
        parent::tearDown();
    }

    public function testTicketLifecycleRepliesNotesTransitionsAndSettings(): void
    {
        $this->token = $this->support;
        // Validation
        $this->assertSame(['subject' => 'REQUIRED', 'body' => 'REQUIRED', 'customer' => 'REQUIRED'], $this->call('POST', '/api/support/tickets', [], 422)['fields']);
        $this->assertEqualsCanonicalizing(['channel' => 'INVALID_CHANNEL', 'customer.email' => 'INVALID_EMAIL', 'locale' => 'INVALID_LOCALE', 'orderId' => 'ORDER_NOT_FOUND'],
            $this->call('POST', '/api/support/tickets', ['subject' => 'S', 'body' => 'B', 'channel' => 'web', 'customer' => ['email' => 'pas-un-courriel'], 'locale' => 'de', 'orderId' => 999999], 422)['fields']);

        // Création par l'agent (appel) : référence, délai en heures ouvrées, demande du client en premier message
        $t = $this->call('POST', '/api/support/tickets', ['subject' => 'Colis abîmé', 'body' => 'Le carton est arrivé écrasé.', 'channel' => 'phone', 'priority' => 'high', 'customer' => ['name' => 'Alice Martin', 'email' => 'alice@example.invalid'], 'locale' => 'en'], 201);
        $this->assertMatchesRegularExpression('/^AS-\d{6}$/', $t['reference']);
        $this->assertSame(['new', 'high', 'phone', 'en', false], [$t['status'], $t['priority'], $t['channel'], $t['customer']['locale'], $t['overdue']]);
        $this->assertSame([['customer', 'Le carton est arrivé écrasé.', false]], array_map(fn ($m) => [$m['authorType'], $m['body'], $m['internal']], $t['messages']));
        $tz = static::getContainer()->get(\App\Services\Booking\BookingClock::class)->timezone();
        // à 2 secondes près : l'échéance part de l'instant du traitement, la date de création de celui de l'entité (1 s d'écart vu sous charge)
        $this->assertEqualsWithDelta(BusinessClock::due(new \DateTimeImmutable($t['createdAt']), 48, [1, 2, 3, 4, 5], $tz)->getTimestamp(), (new \DateTimeImmutable($t['dueAt']))->getTimestamp(), 2, 'délai : 48 h ouvrées');
        $this->assertNull($t['orderSummary']);

        // Liste et filtres
        $this->call('POST', '/api/support/tickets', ['subject' => 'Question de taille', 'body' => 'M ou L ?', 'customer' => ['name' => 'Bob']], 201);
        $this->assertSame(2, $this->call('GET', '/api/support/tickets', null, 200)['total']);
        $this->assertSame(['Colis abîmé'], array_column($this->call('GET', '/api/support/tickets?priority=high', null, 200)['items'], 'subject'));
        $this->assertSame(['Colis abîmé'], array_column($this->call('GET', '/api/support/tickets?q=alice', null, 200)['items'], 'subject'));
        $this->assertSame(['status' => 'INVALID_STATUS'], $this->call('GET', '/api/support/tickets?status=lost', null, 422)['fields']);

        // Transitions et agent
        $this->assertSame('INVALID_STATUS_TRANSITION', $this->call('PATCH', "/api/support/tickets/{$t['id']}", ['status' => 'waiting_customer'], 409)['error']);
        $this->assertSame(['assignedTo' => 'INVALID_AGENT'], $this->call('PATCH', "/api/support/tickets/{$t['id']}", ['assignedTo' => $this->user(['ROLE_USER_INTERNET'])->getId()], 422)['fields']);
        $p = $this->call('PATCH', "/api/support/tickets/{$t['id']}", ['status' => 'open', 'assignedTo' => $this->supportUser->getId()], 200);
        $this->assertSame(['open', $this->supportUser->getId()], [$p['status'], $p['assignedTo']['id']]);
        $this->assertSame([$t['id']], array_column($this->call('GET', '/api/support/tickets?assigned=me', null, 200)['items'], 'id'));

        // Réponse en mode journal (défaut) : rien d'envoyé ; le ticket attend le client
        $r = $this->call('POST', "/api/support/tickets/{$t['id']}/messages", ['body' => 'We are sending a replacement.'], 201);
        $last = end($r['messages']);
        $this->assertSame(['agent', 'logged', 'waiting_customer', null], [$last['authorType'], $last['emailStatus'], $r['status'], $r['dueAt']]);
        $this->assertNotNull($r['firstResponseAt']);
        $this->assertEmailCount(0);

        // Réglages : l'agent les lit, seul l'administrateur les change
        $this->assertSame(['responseHours' => 48, 'businessDays' => [1, 2, 3, 4, 5], 'outboundMode' => 'log', 'inboundAddress' => null, 'retentionYears' => 3], array_intersect_key($this->call('GET', '/api/support/settings', null, 200), array_flip(['responseHours', 'businessDays', 'outboundMode', 'inboundAddress', 'retentionYears'])));
        $this->assertSame('FORBIDDEN', $this->call('PUT', '/api/support/settings', ['outboundMode' => 'send'], 403)['error']);
        $this->token = $this->admin;
        $this->assertSame(['responseHours' => 'OUT_OF_RANGE', 'businessDays' => 'INVALID_DAYS', 'outboundMode' => 'INVALID_MODE'], $this->call('PUT', '/api/support/settings', ['responseHours' => 0, 'businessDays' => [0], 'outboundMode' => 'fax'], 422)['fields']);
        $saved = $this->call('PUT', '/api/support/settings', ['outboundMode' => 'send', 'responseHours' => 24, 'businessDays' => [5, 1, 2, 3, 4, 1]], 200);
        $this->assertSame([24, [1, 2, 3, 4, 5], 'send'], [$saved['responseHours'], $saved['businessDays'], $saved['outboundMode']]);
        $this->token = $this->support;

        // Message du client consigné par l'agent : nouveau délai, le ticket se rouvre ; réponse envoyée par courriel en anglais (langue du client)
        $c = $this->call('POST', "/api/support/tickets/{$t['id']}/messages", ['body' => 'Thanks, when?', 'fromCustomer' => true], 201);
        $this->assertSame(['open', 'customer', true], [$c['status'], end($c['messages'])['authorType'], $c['awaitingReply']]);
        $this->assertNotNull($c['dueAt']);
        $s = $this->call('POST', "/api/support/tickets/{$t['id']}/messages", ['body' => 'Tomorrow.'], 201);
        $this->assertSame('sent', end($s['messages'])['emailStatus']);
        $this->assertEmailCount(1);
        $mail = $this->getMailerMessage();
        $this->assertStringContainsString('Your request ' . $t['reference'], $mail->getSubject());
        $this->assertStringContainsString('Tomorrow.', (string) $mail->getHtmlBody());
        // Note interne : jamais envoyée, état inchangé
        $n = $this->call('POST', "/api/support/tickets/{$t['id']}/messages", ['body' => 'Client fidèle, geste commercial possible.', 'internal' => true], 201);
        $this->assertSame([true, null, 'waiting_customer'], [end($n['messages'])['internal'], end($n['messages'])['emailStatus'], $n['status']]);
        $this->assertEmailCount(0, null, 'note interne : aucun courriel dans cette requête');

        // Fermeture : plus de réponse ; réouverture
        $closed = $this->call('PATCH', "/api/support/tickets/{$t['id']}", ['status' => 'closed'], 200);
        $this->assertNotNull($closed['closedAt']);
        $this->assertSame('TICKET_CLOSED', $this->call('POST', "/api/support/tickets/{$t['id']}/messages", ['body' => 'Encore ?'], 409)['error']);
        $reopened = $this->call('PATCH', "/api/support/tickets/{$t['id']}", ['status' => 'open'], 200);
        $this->assertSame([null, 'open'], [$reopened['closedAt'], $reopened['status']]);
        $this->assertSame('TICKET_NOT_FOUND', $this->call('GET', '/api/support/tickets/999999', null, 404)['error']);
        $this->assertSame(4, (int) $this->db->fetchOne("SELECT COUNT(*) FROM content_audit_log WHERE resource = 'support_ticket' AND resource_id = ?", [(string) $t['id']]), 'création et trois changements (ouverture et agent, fermeture, réouverture) journalisés');
    }

    public function testAttachmentsAreCheckedByContentStoredPrivatelyAndScopedToTheirTicket(): void
    {
        $this->token = $this->support;
        $a = $this->call('POST', '/api/support/tickets', ['subject' => 'Photo', 'body' => 'Voir photo', 'customer' => ['name' => 'Chloé']], 201);
        $b = $this->call('POST', '/api/support/tickets', ['subject' => 'Autre', 'body' => 'Rien', 'customer' => ['name' => 'Dan']], 201);
        $png = $this->file('photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        $fake = $this->file('virus.png', "<?php echo 'pas une image';");
        $r = $this->multipart("/api/support/tickets/{$a['id']}/messages", ['body' => 'Photo du client', 'fromCustomer' => '1'], [$fake]);
        $this->assertSame([422, ['attachments[0]' => 'ATTACHMENT_TYPE']], [$r->getStatusCode(), json_decode((string) $r->getContent(), true)['fields']], 'type vérifié par le contenu, pas par l\'extension');
        $r = $this->multipart("/api/support/tickets/{$a['id']}/messages", ['body' => 'Photo du client', 'fromCustomer' => '1'], [$this->file('photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='))]);
        $this->assertSame(201, $r->getStatusCode(), (string) $r->getContent());
        $att = end(json_decode((string) $r->getContent(), true)['messages'])['attachments'][0];
        $this->assertSame(['photo.png', 'image/png'], [$att['name'], $att['mime']]);
        $this->assertArrayNotHasKey('url', $att, 'jamais d\'URL publique');
        $this->request('GET', "/api/support/tickets/{$a['id']}/attachments/{$att['id']}");
        $this->assertSame([200, 'image/png'], [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->headers->get('Content-Type')]);
        $this->assertStringContainsString('private', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $stored = $this->db->fetchOne('SELECT stored_name FROM support_attachment WHERE id = ?', [$att['id']]);
        $this->assertStringNotContainsString('photo', $stored, 'nom de stockage aléatoire');
        $base = static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/private_media/support/' . MV_TEST_TENANT_CODE . '/';
        $this->assertFileExists($base . $a['id'] . '/' . $stored);
        // même si un fichier du même nom se trouvait dans le dossier du ticket B, la pièce reste liée à son ticket
        @mkdir($base . $b['id'], 0775, true);
        copy($base . $a['id'] . '/' . $stored, $base . $b['id'] . '/' . $stored);
        try {
            $this->assertSame('ATTACHMENT_NOT_FOUND', $this->call('GET', "/api/support/tickets/{$b['id']}/attachments/{$att['id']}", null, 404)['error'], 'pièce d\'un autre ticket');
        } finally {
            @unlink($base . $b['id'] . '/' . $stored);
            @rmdir($base . $b['id']);
        }
        unset($png);
    }

    public function testBusinessHoursSkipNonWorkingDays(): void
    {
        $tz = new \DateTimeZone('America/Toronto');
        $friday = new \DateTimeImmutable('2026-11-13 15:00:00', $tz);
        $this->assertSame('2026-11-17 15:00', BusinessClock::due($friday, 48, [1, 2, 3, 4, 5], $tz)->setTimezone($tz)->format('Y-m-d H:i'), 'vendredi 15 h + 48 h ouvrées = mardi 15 h');
        $saturday = new \DateTimeImmutable('2026-11-14 10:00:00', $tz);
        $this->assertSame('2026-11-18 00:00', BusinessClock::due($saturday, 48, [1, 2, 3, 4, 5], $tz)->setTimezone($tz)->format('Y-m-d H:i'), 'samedi : le compte part du lundi');
        $this->assertSame('2026-11-14 15:00', BusinessClock::due($friday, 24, [1, 2, 3, 4, 5, 6], $tz)->setTimezone($tz)->format('Y-m-d H:i'), 'samedi ouvré');
        $this->assertSame('UTC', BusinessClock::due($friday, 1, [5], $tz)->getTimezone()->getName());
    }

    public function testOrderSummaryShowsOnlyWhatTheCustomerPaidOrSaw(): void
    {
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-assistance@example.invalid', false, false);
        $variant = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $this->db->executeStatement("UPDATE product SET price = 2900, special_price = NULL, purchase_price = 1100, is_pos = true WHERE code = 'DEMO-CASQUETTE'");
        $this->db->executeStatement('UPDATE product_variant SET stock_quantity = 50 WHERE id = ?', [$variant]);
        $this->db->executeStatement("UPDATE entreprise SET pos_price_override_enabled = true, pos_price_override_reason_required = true, pos_price_override_reasons = NULL, pos_price_override_max_amount = NULL, pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement('UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), terminal_enabled = false');
        $register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $this->token = $this->login(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->call('POST', '/api/caisse/open', ['cashRegisterId' => $register, 'cashFund' => 10000], null);
        $items = [['productVariantId' => $variant, 'quantity' => 2, 'priceOverride' => ['unitPrice' => 2500, 'reasonCode' => 'prix_concurrent']]];
        $quote = $this->call('POST', '/api/pos/quote', ['items' => $items, 'cashRegisterId' => $register], 200);
        $sale = $this->call('POST', '/api/order/create-multi-payment', ['items' => $items, 'paymentMethods' => [['type' => 2, 'amount' => $quote['cashTotal'] / 100]], 'cashRegisterId' => $register], 201);

        $this->token = $this->support;
        $t = $this->call('POST', '/api/support/tickets', ['subject' => 'Ticket perdu', 'body' => 'Je voudrais un duplicata', 'channel' => 'counter', 'orderId' => (int) $sale['orderId'], 'customer' => ['name' => 'Eve']], 201);
        $s = $t['orderSummary'];
        $this->assertSame(['id', 'reference', 'date', 'source', 'status', 'lines', 'totals', 'payments', 'returns', 'shipping'], array_keys($s));
        $this->assertSame([['name', 'variant', 'quantity', 'unitPrice', 'discount', 'total']], array_map('array_keys', $s['lines']));
        $this->assertSame([2, 2500, 5000], [$s['lines'][0]['quantity'], $s['lines'][0]['unitPrice'], $s['lines'][0]['total']]);
        $this->assertSame($register, $t['cashRegisterId']);
        $json = json_encode($t);
        foreach (['purchase', 'cost', 'margin', 'catalog', 'supplier', 'fournisseur', 'priceReason', 'note', 'guestToken', 'variantPromotion'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $json, "aucun prix ni note de la gestion : $forbidden");
        }
        // Le prix d'achat (1100) n'apparaît dans aucun montant du résumé de commande. Cherché dans le résumé, pas dans tout le ticket :
        // un identifiant (le compte du client, par exemple) peut valoir 1100 par hasard (13/11/2026, faux échec de la suite complète)
        $this->assertStringNotContainsString('1100', json_encode($s), 'le prix d\'achat n\'est pas rendu dans le résumé de la commande');
    }

    public function testRetentionAnonymizesTicketsClosedMoreThanThreeYearsAgo(): void
    {
        $this->token = $this->support;
        $old = $this->call('POST', '/api/support/tickets', ['subject' => 'Vieux', 'body' => 'Données personnelles', 'customer' => ['name' => 'Fanny', 'email' => 'fanny@example.invalid']], 201);
        $this->multipart("/api/support/tickets/{$old['id']}/messages", ['body' => 'Photo', 'fromCustomer' => '1'], [$this->file('p.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='))]);
        $recent = $this->call('POST', '/api/support/tickets', ['subject' => 'Récent', 'body' => 'Gardé', 'customer' => ['name' => 'Gus']], 201);
        $this->call('PATCH', "/api/support/tickets/{$old['id']}", ['status' => 'closed'], 200);
        $this->call('PATCH', "/api/support/tickets/{$recent['id']}", ['status' => 'closed'], 200);
        $this->db->executeStatement("UPDATE support_ticket SET closed_at = NOW() - INTERVAL '3 years 1 day' WHERE id = ?", [$old['id']]);
        $this->db->executeStatement("UPDATE support_ticket SET closed_at = NOW() - INTERVAL '2 years 360 days' WHERE id = ?", [$recent['id']]);
        $stored = $this->db->fetchOne('SELECT a.stored_name FROM support_attachment a JOIN support_message m ON m.id = a.message_id WHERE m.ticket_id = ?', [$old['id']]);
        $file = static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/private_media/support/' . MV_TEST_TENANT_CODE . '/' . $old['id'] . '/' . $stored;
        $retention = static::getContainer()->get(SupportRetentionService::class);

        $this->assertSame([$old['reference']], $retention->run(false), 'simulation');
        $this->assertSame('fanny@example.invalid', $this->db->fetchOne('SELECT customer_email FROM support_ticket WHERE id = ?', [$old['id']]), 'rien d\'écrit en simulation');
        $this->assertSame([$old['reference']], $retention->run(true));
        $row = $this->db->fetchAssociative('SELECT customer_name, customer_email, subject, anonymized_at, reference, status FROM support_ticket WHERE id = ?', [$old['id']]);
        $this->assertSame([null, null, SupportRetentionService::PLACEHOLDER, $old['reference'], 'closed'], [$row['customer_name'], $row['customer_email'], $row['subject'], $row['reference'], $row['status']]);
        $this->assertNotNull($row['anonymized_at']);
        $this->assertSame([SupportRetentionService::PLACEHOLDER], array_values(array_unique($this->db->fetchFirstColumn('SELECT body FROM support_message WHERE ticket_id = ?', [$old['id']]))));
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM support_attachment a JOIN support_message m ON m.id = a.message_id WHERE m.ticket_id = ?', [$old['id']]));
        $this->assertFileDoesNotExist($file, 'pièce jointe supprimée');
        $this->assertSame('Gus', $this->db->fetchOne('SELECT customer_name FROM support_ticket WHERE id = ?', [$recent['id']]), 'fermé depuis moins de 3 ans : gardé');
        $this->assertSame([], $retention->run(true), 'rejouable sans effet');
    }

    public function testModuleGateInLiveModeLoginAndReadiness(): void
    {
        // Connexion d'un compte Assistance à l'application de gestion : rôles dans le jeton
        $this->token = $this->login(['ROLE_SUPPORT'], 'admin');
        $payload = json_decode(base64_decode(strtr(explode('.', $this->token)[1], '-_', '+/')), true);
        $this->assertContains('ROLE_SUPPORT', $payload['roles']);
        $this->assertNotContains('ROLE_ADMIN', $payload['roles']);
        // Garde du module (mode live seulement)
        $this->call('GET', '/api/support/tickets', null, 200);
        static::getContainer()->get(PlatformModeService::class)->setMode('live', 'test');
        $this->db->executeStatement('UPDATE entreprise SET is_support_active = false');
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        $r = $this->call('GET', '/api/support/tickets', null, 403);
        $this->assertSame(['MODULE_INACTIVE', 'support', 'module.support'], [$r['error'], $r['module'], $r['feature']]);
        static::getContainer()->get(EntitlementService::class)->grant(MV_TEST_TENANT_CODE, 'module.support', 'manual', null, 'test', 'test', null, null, false);
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        $this->call('GET', '/api/support/tickets', null, 200);
        // Cheminement : groupe Assistance, seulement module accordé
        $this->token = $this->admin;
        $steps = array_column(array_values(array_filter($this->call('GET', '/api/site/readiness', null, 200)['steps'], fn ($s) => $s['group'] === 'module.support')), null, 'key');
        $this->assertSame(['support.agent', 'support.settings', 'support.outbound', 'support.inbound'], array_keys($steps));
        $this->assertSame(['done', 'todo', 'assistance-reglages', true], [$steps['support.agent']['status'], $steps['support.settings']['status'], $steps['support.settings']['appScreen'], $steps['support.settings']['blocking']]);
    }

    // ----- helpers -----

    private function clean(): void
    {
        foreach (['support_attachment', 'support_message', 'support_ticket', 'support_setting'] as $t) {
            $this->db->executeStatement("DELETE FROM $t");
        }
        $this->db->executeStatement("DELETE FROM content_audit_log WHERE resource = 'support_ticket'");
        $this->db->executeStatement('UPDATE entreprise SET is_support_active = false');
        $master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $master->prepare("DELETE FROM tenant_entitlement_events WHERE entitlement_id IN (SELECT id FROM tenant_entitlements WHERE tenant_code = ? AND feature_key = 'module.support')")->execute([MV_TEST_TENANT_CODE]);
        $master->prepare("DELETE FROM tenant_entitlements WHERE tenant_code = ? AND feature_key = 'module.support'")->execute([MV_TEST_TENANT_CODE]);
    }

    private function file(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'as');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function multipart(string $path, array $fields, array $files): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . $path, $fields, ['attachments' => $files], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token]);

        return $this->client->getResponse();
    }

    private function call(string $method, string $path, ?array $body, ?int $expected): array
    {
        $response = $this->request($method, $path, $body);
        if ($expected !== null) {
            $this->assertSame($expected, $response->getStatusCode(), "$method $path : " . substr((string) $response->getContent(), 0, 600));
        }

        return json_decode((string) $response->getContent(), true) ?? [];
    }

    private function request(string $method, string $path, ?array $body = null): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null,
        ]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function login(array $roles, string $platform = 'pos'): string
    {
        $user = $this->user($roles);
        $this->token = null;
        $r = $this->request('POST', '/api/login', ['username' => $user->getEmail(), 'password' => self::PASSWORD, 'platform' => $platform]);
        $this->assertSame(200, $r->getStatusCode(), (string) $r->getContent());

        return json_decode((string) $r->getContent(), true)['token'];
    }

    private function user(array $roles): User
    {
        $email = 'assistance-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Agent')->setLastname('Assistance')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $user;
    }
}
