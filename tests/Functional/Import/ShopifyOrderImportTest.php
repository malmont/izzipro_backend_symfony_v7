<?php

namespace App\Tests\Functional\Import;

use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\OrderSource;
use App\Entity\OrderType;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\StatusCommande;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Import\ImportGuests;
use App\Import\Message\RunOrderImportMessage;
use App\Import\OrderArchive;
use App\Import\OrderImportService;
use App\Services\AccountService\PersonalDataEraser;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\CaisseService\StoreClock;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Import de l'historique des commandes d'une boutique existante, lot IMP-3 (16/11/2026, `docs/import-boutique.md` § 10). Le fichier
 * s'analyse sans rien écrire ; les commandes vont dans une ARCHIVE À PART, jamais dans les commandes du site ; aucune donnée
 * personnelle n'y est recopiée ; rejoué, l'import ne double rien ; il s'annule. Seules les commandes payées entrent dans la série
 * « ventes d'avant » ; la comparaison « an dernier » ne lit l'archive que si le site n'a aucune vraie vente sur la plage, et le dit.
 */
class ShopifyOrderImportTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private KernelBrowser $client;
    private string $token = '';
    private string $tag;
    private int $adminId = 0;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->tag = bin2hex(random_bytes(3));
        $this->wipe();
        $this->token = $this->tokenFor(['ROLE_ADMIN']);
    }

    protected function tearDown(): void
    {
        $this->wipe();
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testOrdersGoToASeparateArchiveWithoutPersonalDataReplayWithoutDuplicateAndCanBeCancelled(): void
    {
        $t = $this->tag;
        $conn = $this->em()->getConnection();
        $currency = strtoupper(static::getContainer()->get(TenantCurrencyProvider::class)->code(true));
        $other = $currency === 'EUR' ? 'USD' : 'EUR';
        $alice = $this->account("alice-imp3-$t@example.invalid", 'Alice', ['ROLE_USER_INTERNET']);
        $ago = static fn (string $delay): string => (new \DateTimeImmutable($delay, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s') . ' +0000';
        $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, code) VALUES (nextval('product_id_seq'), 'Tasse', 1500, false, 0, NOW(), ?, 'standard', ?)", ["imp3-test-$t", "TASSE-$t"]);
        $productId = (int) $conn->fetchOne('SELECT id FROM product WHERE slug = ?', ["imp3-test-$t"]);
        // deux articles portent la même référence : elle ne désigne aucun des deux
        foreach (['x', 'y'] as $twin) {
            $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, code) VALUES (nextval('product_id_seq'), 'Jumeau', 1500, false, 0, NOW(), ?, 'standard', ?)", ["imp3-test-$twin-$t", "DOUBLE-$t"]);
        }
        // la lecture d'un montant et d'une date du fichier
        // (un séparateur de milliers n'est jamais deviné : « 1,234.50 » et « 1.234 » sont refusés plutôt que lus mille fois trop haut ou trop bas)
        $this->assertSame([123450, 123450, 1250, 1200, 0, null, null, null, null, null, null, null], array_map(OrderImportService::cents(...), ['1 234,50', '1234.50', '12,5', '12', '0.00', '', '-5', 'abc', '1.2.3', '1,234.50', '1.234', '1234567890']));
        $this->assertSame(['2025-03-05 19:23:11', '2025-03-05 00:00:00', '2025-03-05 14:23:00', null, null, null, null], array_map(static fn (string $d): ?string => OrderImportService::date($d)?->format('Y-m-d H:i:s'),
            ['2025-03-05 14:23:11 -0500', '2025-03-05', '2025-03-05T14:23Z', 'hier', '05/03/2025', '', '2025-02-30 10:00']));
        // LE PLAN, cas par cas (sans rien déposer) : états retenus, refus, repli sur la date de création, part remboursée, lignes bornées
        $line = ['line' => 2, 'name' => "Carte\x00 cadeau\x1F", 'quantity' => '2', 'price' => '5.00', 'sku' => ''];
        $base = ['line' => 2, 'email' => '', 'paidAt' => $ago('-2 days'), 'createdAt' => $ago('-2 days'), 'cancelledAt' => '', 'currency' => $currency, 'total' => '10.00', 'taxes' => '', 'shipping' => '', 'refunded' => '', 'lines' => [$line]];
        $this->em();
        $plan = static::getContainer()->get(OrderImportService::class)->plan([
            ['ref' => '#P1', 'financial' => 'voided'] + $base, ['ref' => '#P2', 'financial' => 'authorized'] + $base, ['ref' => '#P3', 'financial' => 'partially_paid'] + $base,
            ['ref' => '#P4', 'financial' => 'paid', 'currency' => ''] + $base, ['ref' => '#P5', 'financial' => 'paid', 'paidAt' => $ago('+3 days')] + $base,
            ['ref' => '#P6', 'financial' => 'paid', 'paidAt' => 'illisible'] + $base, ['ref' => '#P7', 'financial' => 'paid', 'taxes' => 'abc'] + $base, ['ref' => '#P1', 'financial' => 'paid'] + $base,
            // 100 hors taxes + 15 de taxes, la moitié rendue (57,50 taxes comprises) : il reste 50 hors taxes, pas 42,50
            ['ref' => '#P8', 'financial' => 'partially_refunded', 'total' => '115.00', 'taxes' => '15.00', 'shipping' => '0', 'refunded' => '57.50'] + $base,
            ['ref' => "#P9\x07", 'financial' => 'paid', 'lines' => array_fill(0, OrderImportService::MAX_LINES + 5, $line)] + $base,
        ], 'shopify');
        $this->assertSame([['ok', 'cancelled'], ['ok', 'unpaid'], ['ok', 'unpaid'], ['refused', 'CURRENCY_MISSING'], ['refused', 'DATE_INVALID'], ['ok', 'paid'], ['refused', 'AMOUNT_INVALID'], ['duplicate', null], ['ok', 'paid'], ['ok', 'paid']],
            array_map(static fn (array $p): array => [$p['status'], $p['status'] === 'ok' ? $p['state'] : $p['code']], $plan));
        $this->assertSame([5000, '#P9', OrderImportService::MAX_LINES, 'Carte cadeau', 2], [$plan[8]['net'], $plan[9]['ref'], count($plan[9]['lines']), $plan[0]['lines'][0]['name'], $plan[0]['items']]);
        $this->assertContains(['line' => 2, 'code' => 'DUPLICATE_ORDER'], static::getContainer()->get(OrderImportService::class)->report($plan)['issues']);
        $before = ['orders' => (int) $conn->fetchOne('SELECT COUNT(*) FROM "order"'), 'items' => (int) $conn->fetchOne('SELECT COUNT(*) FROM order_items'), 'users' => (int) $conn->fetchOne('SELECT COUNT(*) FROM "user"')];
        $head = 'Name,Email,Financial Status,Paid at,Fulfillment Status,Currency,Subtotal,Shipping,Taxes,Total,Created at,Lineitem quantity,Lineitem name,Lineitem price,Lineitem sku,Billing Name,Billing Street,Shipping Phone,Notes,Payment Method,Cancelled at,Refunded Amount';
        $csv = implode("\n", [
            $head,
            // payée, 2 lignes, le courriel d'un compte du site (casse différente), prix taxes en plus : net = 125 − 15 − 10 = 100
            "#1001,ALICE-imp3-$t@Example.invalid,paid,{$ago('-3 months')},fulfilled,$currency,100.00,10.00,15.00,125.00,{$ago('-3 months')},2,Tasse bleue,30.00,TASSE-$t,Alice Martin,12 rue des Érables,+15145550101,Note privée du client,Visa,,0.00",
            // payée, sans compte correspondant : gardée sans identité
            "#1002,inconnu-imp3-$t@example.invalid,paid,{$ago('-2 months')},fulfilled,$currency,50.00,0.00,0.00,50.00,{$ago('-2 months')},1,Affiche,50.00,,Bob Sans Compte,1 rue X,,,Mastercard,,",
            // remboursée en partie : dans la série, hors taxes réduit de la PART remboursée : (80 − 10) × (1 − 20 / 80) = 52,50
            "#1003,alice-imp3-$t@example.invalid,partially_refunded,{$ago('-40 days')},fulfilled,$currency,70.00,0.00,10.00,80.00,{$ago('-40 days')},2,Tasse bleue,35.00,TASSE-$t,,,,,,,20.00",
            // remboursée, annulée, impayée : gardées, marquées, hors de la série
            "#1004,,refunded,{$ago('-30 days')},,$currency,20.00,0.00,0.00,20.00,{$ago('-30 days')},1,Stylo,20.00,,,,,,,,20.00",
            "#1005,,paid,{$ago('-20 days')},,$currency,60.00,0.00,0.00,60.00,{$ago('-20 days')},1,Sac,60.00,,,,,,,{$ago('-19 days')},",
            "#1006,,pending,,,$currency,15.00,0.00,0.00,15.00,{$ago('-10 days')},1,Gomme,15.00,,,,,,,,",
            // trop ancienne (plus de 24 mois), autre devise, date illisible, total illisible, ligne sans numéro
            "#1007,,paid,{$ago('-30 months')},,$currency,10.00,0.00,0.00,10.00,{$ago('-30 months')},1,Vieux,10.00,,,,,,,,",
            "#1008,,paid,{$ago('-5 days')},,$other,10.00,0.00,0.00,10.00,{$ago('-5 days')},1,Ailleurs,10.00,,,,,,,,",
            "#1009,,paid,hier,,$currency,10.00,0.00,0.00,10.00,hier,1,Sans date,10.00,,,,,,,,",
            "#1010,,paid,{$ago('-5 days')},,$currency,10.00,0.00,0.00,beaucoup,{$ago('-5 days')},1,Sans total,10.00,,,,,,,,",
            ",,paid,{$ago('-5 days')},,$currency,10.00,0.00,0.00,10.00,{$ago('-5 days')},1,Sans numéro,10.00,,,,,,,,",
            // la seconde ligne de la commande #1001, loin de la première : les lignes d'une commande ne se suivent pas forcément
            "#1001,,,,,,,,,,,1,Carnet,40.00,double-$t,,,,,,,",
        ]) . "\n";

        // Un fichier qui n'est pas un export de commandes est refusé ; une nature inconnue aussi ; un client du site n'y a pas accès
        $this->upload("Customer ID,First Name,Last Name,Email\n'1,A,B,a@example.invalid\n", 'orders');
        $this->assertSame([422, 'IMPORT_NOT_AN_ORDER_FILE'], [$this->status(), $this->body()['error']]);
        $admin = $this->token;
        $this->token = $this->tokenFor(['ROLE_USER_INTERNET']);
        $this->upload($csv, 'orders');
        $this->assertSame(403, $this->status());
        $this->request('GET', "/api/site/imports/archive/orders?userId=$alice");
        $this->assertSame(403, $this->status());
        $this->token = $admin;

        // DÉPÔT : l'analyse dit ce qui serait repris, sans rien écrire ; le rapport ne porte ni courriel, ni nom, ni adresse
        $this->upload($csv, 'orders');
        $this->assertSame(201, $this->status(), (string) $this->client->getResponse()->getContent());
        $job = $this->body();
        $id = (int) $job['id'];
        $this->assertSame(['orders', 'analysed', 12, 0], [$job['kind'], $job['status'], $job['rows'], $job['archived']]);
        $o = $job['report']['orders'];
        $this->assertEquals(['total' => 11, 'importable' => 6, 'paid' => 3, 'refunded' => 1, 'cancelled' => 1, 'unpaid' => 1, 'already' => 0, 'tooOld' => 1, 'refused' => 4, 'linkedToAccount' => 2,
            'withoutAccount' => 4, 'lines' => 7, 'linesWithProduct' => 2, 'netCents' => 20250], array_diff_key($o, ['firstDay' => 1, 'lastDay' => 1]));
        $this->assertSame([substr($ago('-3 months'), 0, 4), substr($ago('-10 days'), 0, 4)], [substr((string) $o['firstDay'], 0, 4), substr((string) $o['lastDay'], 0, 4)]);
        $this->assertSame([OrderImportService::MONTHS, $currency], [$job['report']['months'], $job['report']['currency']]);
        $codes = array_column($job['report']['issues'], 'code', 'line');
        $this->assertSame([9 => 'CURRENCY_MISMATCH', 10 => 'DATE_INVALID', 11 => 'AMOUNT_INVALID', 12 => 'REF_MISSING'], $codes);
        $raw = (string) $this->client->getResponse()->getContent();
        foreach (["imp3-$t", 'Alice', 'Érables', '5550101', 'Note privée', 'Visa', 'Bob'] as $personal) {
            $this->assertStringNotContainsStringIgnoringCase($personal, $raw, "le rapport ne porte pas « $personal »");
        }
        $this->assertSame([0, 0], [(int) $conn->fetchOne('SELECT COUNT(*) FROM import_order'), (int) $conn->fetchOne('SELECT COUNT(*) FROM import_order_line')], 'l\'analyse n\'écrit rien');
        $this->request('GET', "/api/site/imports/$id/issues.csv");
        $this->assertSame(200, $this->status());
        $this->assertSame(["line;code", '9;CURRENCY_MISMATCH', '10;DATE_INVALID', '11;AMOUNT_INVALID', '12;REF_MISSING'], array_values(array_filter(explode("\n", str_replace(["\xEF\xBB\xBF", "\r"], '', (string) $this->client->getResponse()->getContent())))));
        // un seul import actif par site, toutes natures
        $this->upload($csv, 'orders');
        $this->assertSame([409, 'IMPORT_ALREADY_ACTIVE'], [$this->status(), $this->body()['error']]);
        $list = $this->get('/api/site/imports')['imports'];
        $this->assertSame(['orders', 6], [$list[0]['kind'], $list[0]['summary']['orders']['importable']]);

        // CONFIRMATION : mise en file sur le worker des médias ; EXÉCUTION : six commandes en archive
        $this->request('POST', "/api/site/imports/$id/confirm", []);
        $this->assertSame([202, 'running'], [$this->status(), $this->body()['status']]);
        $queued = static::getContainer()->get('messenger.transport.media')->getSent();
        $this->assertInstanceOf(RunOrderImportMessage::class, end($queued)->getMessage());
        $this->assertSame([$id, MV_TEST_TENANT_CODE], [end($queued)->getMessage()->jobId, end($queued)->getMessage()->tenantCode]);
        $this->runImport($id);
        $done = $this->get("/api/site/imports/$id");
        $this->assertEquals(['done', 6, ['created' => 6, 'paid' => 3, 'linkedToAccount' => 2, 'lines' => 7, 'skipped' => 0, 'tooOld' => 1, 'refused' => 4, 'failed' => 0, 'failures' => []]], [$done['status'], $done['archived'], $done['result']]);
        $this->assertSame([], glob(static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/private_media/imports/' . MV_TEST_TENANT_CODE . '/*.csv') ?: [], 'le fichier est supprimé du serveur');
        $rows = array_column($conn->fetchAllAssociative('SELECT ref, state, financial_status, total_cents, tax_cents, shipping_cents, refunded_cents, net_cents, currency, user_id, items_count, day, ordered_at FROM import_order ORDER BY ref'), null, 'ref');
        $this->assertSame(['#1001', '#1002', '#1003', '#1004', '#1005', '#1006'], array_keys($rows));
        $this->assertSame(['paid', 'paid', 12500, 1500, 1000, 0, 10000, $currency, $alice, 3], array_values(array_diff_key($rows['#1001'], ['ref' => 1, 'day' => 1, 'ordered_at' => 1])));
        $this->assertSame(static::getContainer()->get(StoreClock::class)->day(new \DateTimeImmutable($rows['#1001']['ordered_at'], new \DateTimeZone('UTC'))), substr((string) $rows['#1001']['day'], 0, 10), 'le jour est celui du magasin');
        $this->assertSame([['paid', 5000, null], ['paid', 5250, $alice], ['refunded', 0, null], ['cancelled', 6000, null], ['unpaid', 1500, null]],
            array_map(static fn (string $ref): array => [$rows[$ref]['state'], (int) $rows[$ref]['net_cents'], $rows[$ref]['user_id']], ['#1002', '#1003', '#1004', '#1005', '#1006']));
        $this->assertSame([[1, 'Tasse bleue', 2, 3000, $productId], [2, 'Carnet', 1, 4000, null]],
            $conn->fetchAllNumeric("SELECT l.position, l.name, l.quantity, l.unit_price_cents, l.product_id FROM import_order_line l JOIN import_order o ON o.id = l.order_id WHERE o.ref = '#1001' ORDER BY l.position"));
        // AUCUNE donnée personnelle dans l'archive, et rien d'autre du site n'a bougé : ni commande, ni ligne, ni compte, ni courriel
        $stored = json_encode([$conn->fetchAllAssociative('SELECT * FROM import_order'), $conn->fetchAllAssociative('SELECT * FROM import_order_line'), $conn->fetchAllAssociative('SELECT report, result, options FROM import_job')], JSON_UNESCAPED_UNICODE);
        foreach (["imp3-$t", 'Alice', 'Martin', 'Érables', '5550101', 'Note privée', 'Visa', 'Bob'] as $personal) {
            $this->assertStringNotContainsStringIgnoringCase($personal, (string) $stored, "l'archive ne garde pas « $personal »");
        }
        $this->assertSame($before, ['orders' => (int) $conn->fetchOne('SELECT COUNT(*) FROM "order"'), 'items' => (int) $conn->fetchOne('SELECT COUNT(*) FROM order_items'), 'users' => (int) $conn->fetchOne('SELECT COUNT(*) FROM "user"') - 1]);
        $this->assertCount(0, self::getMailerMessages());

        // La fiche du client côté administration : ses commandes d'avant, avec leurs lignes ; un autre compte n'en a aucune
        $mine = $this->get("/api/site/imports/archive/orders?userId=$alice");
        $this->assertSame([2, 1, 1, ['#1003', '#1001']], [$mine['total'], $mine['page'], $mine['pages'], array_column($mine['orders'], 'ref')]);
        $this->assertSame(['state' => 'paid', 'financialStatus' => 'paid', 'total' => 12500, 'taxes' => 1500, 'shipping' => 1000, 'refunded' => 0, 'net' => 10000, 'currency' => $currency, 'items' => 3,
            'lines' => [['name' => 'Tasse bleue', 'quantity' => 2, 'unitPrice' => 3000, 'productId' => $productId], ['name' => 'Carnet', 'quantity' => 1, 'unitPrice' => 4000, 'productId' => null]]],
            array_diff_key($mine['orders'][1], ['ref' => 1, 'orderedAt' => 1, 'day' => 1]));
        $this->assertSame(0, $this->get("/api/site/imports/archive/orders?userId={$this->adminId}")['total']);
        $this->request('GET', '/api/site/imports/archive/orders');
        $this->assertSame(422, $this->status());
        $this->client->loginUser($this->em()->find(User::class, $this->adminId), 'main');
        $page = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/clients/$alice/commandes-avant", [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), substr((string) $this->client->getResponse()->getContent(), 0, 400));
        $this->assertSame([['#1003', 'paid'], ['#1001', 'paid']], $page->filter('table.commandes-avant tr[data-ref]')->each(static fn ($row): array => [$row->attr('data-ref'), $row->attr('data-etat')]));
        $this->client->getCookieJar()->clear();

        // La série « ventes d'avant » : les commandes PAYÉES seulement, à part ; bornes facultatives
        $series = $this->get('/api/gestion/statistiques/avant-migration');
        $this->assertSame([OrderArchive::SOURCE, $currency, ['revenue' => 20250, 'orders' => 3, 'items' => 6, 'averageBasket' => 6750], ['paid' => 3, 'refunded' => 1, 'cancelled' => 1, 'unpaid' => 1]],
            [$series['source'], $series['currency'], $series['totals'], $series['states']]);
        $this->assertSame(20250, array_sum(array_column($series['months'], 'revenue')));
        $this->assertSame([substr((string) $rows['#1001']['day'], 0, 10), substr((string) $rows['#1003']['day'], 0, 10)], [$series['firstDay'], $series['lastDay']]);
        $recent = $this->get('/api/gestion/statistiques/avant-migration?from=' . substr((string) $rows['#1003']['day'], 0, 10));
        $this->assertSame(['revenue' => 5250, 'orders' => 1, 'items' => 2, 'averageBasket' => 5250], $recent['totals']);
        $this->request('GET', '/api/gestion/statistiques/avant-migration?from=2025-13-40');
        $this->assertSame(422, $this->status());

        // REJEU : le même fichier ne double rien ; rien à reprendre, donc rien à confirmer
        $this->upload($csv, 'orders');
        $again = $this->body();
        $this->assertSame([201, 0, 6], [$this->status(), $again['report']['orders']['importable'], $again['report']['orders']['already']]);
        $this->request('POST', "/api/site/imports/{$again['id']}/confirm", []);
        $this->assertSame([409, 'IMPORT_NOTHING_TO_IMPORT'], [$this->status(), $this->body()['error']]);
        $this->request('POST', "/api/site/imports/{$again['id']}/cancel");
        $this->assertSame([200, 'cancelled'], [$this->status(), $this->body()['status']]);
        // un fichier avec une commande de plus ne reprend qu'elle
        $this->upload($csv . "#1011,,paid,{$ago('-1 day')},,$currency,30.00,0.00,0.00,30.00,{$ago('-1 day')},1,Nouveau,30.00,,,,,,,,\n", 'orders');
        $more = $this->body();
        $this->assertSame([1, 6], [$more['report']['orders']['importable'], $more['report']['orders']['already']]);
        $this->request('POST', "/api/site/imports/{$more['id']}/confirm", []);
        $this->runImport((int) $more['id']);
        $this->assertEquals([7, ['created' => 1, 'skipped' => 6]], [(int) $conn->fetchOne('SELECT COUNT(*) FROM import_order'), array_intersect_key($this->get("/api/site/imports/{$more['id']}")['result'], ['created' => 1, 'skipped' => 1])]);

        // EFFACEMENT du compte à sa demande : ses commandes d'avant restent dans la série, sans lien avec lui
        static::getContainer()->get(PersonalDataEraser::class)->erase($this->em()->find(User::class, $alice));
        $this->endOfWrite();
        $this->assertSame([0, 7], [(int) $conn->fetchOne('SELECT COUNT(*) FROM import_order WHERE user_id IS NOT NULL'), (int) $conn->fetchOne('SELECT COUNT(*) FROM import_order')]);
        $this->assertSame(0, $this->get("/api/site/imports/archive/orders?userId=$alice")['total']);
        $this->assertSame(23250, $this->get('/api/gestion/statistiques/avant-migration')['totals']['revenue']);

        // PURGE : un fichier de commandes déposé et jamais confirmé depuis 7 jours est abandonné, et supprimé du serveur
        $directory = static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/private_media/imports/' . MV_TEST_TENANT_CODE . '/*.csv';
        $extra = $csv . "#1011,,paid,{$ago('-1 day')},,$currency,30.00,0.00,0.00,30.00,{$ago('-1 day')},1,Nouveau,30.00,,,,,,,,\n#1012,,paid,{$ago('-1 day')},,$currency,9.00,0.00,0.00,9.00,{$ago('-1 day')},1,Dernier,9.00,,,,,,,,\n";
        $this->upload($extra, 'orders');
        $old = (int) $this->body()['id'];
        $this->assertCount(1, glob($directory) ?: []);
        $this->assertSame('analysed', $this->get('/api/site/imports')['imports'][0]['status'], 'six jours : rien ne bouge');
        $conn->executeStatement("UPDATE import_job SET created_at = NOW() - INTERVAL '8 days' WHERE id = ?", [$old]);
        $this->assertSame([$old, 'cancelled'], [$this->get('/api/site/imports')['imports'][0]['id'], $this->get('/api/site/imports')['imports'][0]['status']]);
        $this->assertSame([], glob($directory) ?: [], 'le fichier abandonné est supprimé');
        // Un import resté « en cours » ne s'annule qu'une fois tenu pour interrompu ; annulé, son worker n'écrit plus rien
        $this->upload($extra, 'orders');
        $late = (int) $this->body()['id'];
        $this->request('POST', "/api/site/imports/$late/confirm", []);
        $this->request('POST', "/api/site/imports/$late/cancel");
        $this->assertSame([409, 'IMPORT_NOT_CANCELLABLE'], [$this->status(), $this->body()['error']], 'en cours depuis une minute : pas encore');
        $conn->executeStatement("UPDATE import_job SET started_at = NOW() - INTERVAL '2 hours' WHERE id = ?", [$late]);
        $this->request('POST', "/api/site/imports/$late/cancel");
        $this->assertSame([200, 'cancelled', 'IMPORT_INTERRUPTED'], [$this->status(), $this->body()['status'], $this->body()['error']]);
        $this->runImport($late);
        $this->assertSame([7, 0, 'cancelled'], [(int) $conn->fetchOne('SELECT COUNT(*) FROM import_order'), (int) $conn->fetchOne('SELECT COUNT(*) FROM import_order WHERE job_id = ?', [$late]), $this->get("/api/site/imports/$late")['status']], 'rien n\'est écrit sous un import annulé');
        $this->assertSame([], glob($directory) ?: []);

        // ANNULATION : à tout moment, elle retire de l'archive les commandes de CET import, et elles seules
        $this->request('POST', "/api/site/imports/$id/cancel");
        $cancelled = $this->body();
        $this->assertSame([200, 'cancelled', 0, ['removed' => 6]], [$this->status(), $cancelled['status'], $cancelled['archived'], $cancelled['result']['cancel']]);
        $this->assertSame([['#1011']], $conn->fetchAllNumeric('SELECT ref FROM import_order'));
        $this->assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM import_order_line'), 'les lignes partent avec leur commande');
        $this->request('POST', "/api/site/imports/$id/cancel");
        $this->assertSame([409, 'IMPORT_NOT_CANCELLABLE'], [$this->status(), $this->body()['error']]);
        $this->assertSame(['cancel', 'cancel', 'run', 'run'], $conn->fetchFirstColumn("SELECT action FROM content_audit_log WHERE resource = 'import_job' AND action IN ('run', 'cancel') ORDER BY action, id"));
        // le journal du site ne porte, lui non plus, aucune donnée personnelle
        $this->assertStringNotContainsStringIgnoringCase("imp3-$t", (string) json_encode($conn->fetchAllAssociative("SELECT * FROM content_audit_log WHERE resource = 'import_job'")));
        // un import terminé dont il ne reste rien en archive n'a plus rien à annuler
        $conn->executeStatement('DELETE FROM import_order WHERE job_id = ?', [(int) $more['id']]);
        $this->assertFalse($this->get("/api/site/imports/{$more['id']}")['cancellable']);
        $this->request('POST', "/api/site/imports/{$more['id']}/cancel");
        $this->assertSame([409, 'IMPORT_NOT_CANCELLABLE'], [$this->status(), $this->body()['error']]);
    }

    public function testLastYearComesFromTheArchiveOnlyWhenTheSiteHasNoRealSaleOverThatRangeAndSaysSo(): void
    {
        $t = $this->tag;
        $conn = $this->em()->getConnection();
        $currency = strtoupper(static::getContainer()->get(TenantCurrencyProvider::class)->code(true));
        // Trois commandes d'avant en mars 2010 (deux payées, une remboursée) et une payée en juin 2010 : très loin de toute vente du site
        foreach ([['#A1', '2010-03-10', 'paid', 12000, 3], ['#A2', '2010-03-20', 'paid', 8000, 1], ['#A3', '2010-03-21', 'refunded', 0, 1], ['#A4', '2010-06-02', 'paid', 5000, 2], ['#A5', '2010-04-12', 'paid', 3000, 1]] as [$ref, $day, $state, $net, $items]) {
            $conn->executeStatement("INSERT INTO import_order (job_id, source, ref, ordered_at, day, financial_status, state, total_cents, net_cents, currency, items_count) VALUES (0, 'shopify', ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                ["$ref-$t", "$day 15:00:00", $day, $state, $state, $net, $net, $currency, $items]);
        }
        $archive = static::getContainer()->get(OrderArchive::class);
        $this->assertSame(['2010-03-10' => [12000, 1, 3, 12000], '2010-03-20' => [8000, 1, 1, 8000]],
            array_map(static fn (array $r): array => [$r['sales_amount'], $r['orders_sold'], $r['items_sold'], $r['unknown_revenue']], $archive->dailySales('2010-03-01', '2010-03-31')));

        // STATISTIQUES de mars 2011 : le site n'a aucune vente en mars 2010 ; l'an dernier vient de l'archive, et la réponse le dit
        $stats = $this->get('/api/gestion/statistiques?from=2011-03-01&to=2011-03-31&locale=fr');
        $this->assertSame(['from' => '2010-03-01', 'to' => '2010-03-31', 'source' => OrderArchive::SOURCE], $stats['lastYear']);
        $this->assertStringEndsWith('(avant la migration)', $stats['comparison']['lastYearLabel']);
        $i = $stats['indicators'];
        $this->assertSame([20000, 2, 10000], [$i['revenue']['lastYear'], $i['orders']['lastYear'], $i['averageBasket']['lastYear']]);
        $points = array_column($stats['chart']['points'], null, 'key');
        $this->assertSame(['revenue' => 12000, 'grossMargin' => null, 'orders' => 1], $points['2011-03-10']['lastYear'], 'le point du graphique : chiffre et commandes, marge vide');
        // ce que l'archive ne connaît pas reste VIDE, jamais zéro : marge, taux de marge, retours, locations, abonnements
        $this->assertSame([null, null, null, null, null], [$i['grossMargin']['lastYear'], $i['grossMarginRate']['lastYear'], $i['returnRate']['lastYear'], $stats['otherRevenue']['rentals']['lastYear'], $stats['otherRevenue']['subscriptions']['lastYear']]);
        $codes = array_column($stats['caveats'], 'code');
        $this->assertContains('LAST_YEAR_BEFORE_MIGRATION', $codes);
        $this->assertNotContains('NO_LAST_YEAR_DATA', $codes);
        // les ventes d'avant ne sont JAMAIS dans les chiffres du site : la plage de mars 2010 elle-même ne les compte pas
        $itself = $this->get('/api/gestion/statistiques?from=2010-03-01&to=2010-03-31');
        $this->assertSame([0, 0], [$itself['indicators']['revenue']['value'], $itself['indicators']['orders']['value']]);
        // un filtre de canal ne lit pas l'archive (elle n'a pas de canal)
        $pos = $this->get('/api/gestion/statistiques?from=2011-03-01&to=2011-03-31&source=pos');
        $this->assertSame(['site', null], [$pos['lastYear']['source'], $pos['indicators']['revenue']['lastYear']]);
        $this->assertContains('NO_LAST_YEAR_DATA', array_column($pos['caveats'], 'code'));
        // une plage sans archive : rien ne change, la réponse dit « site »
        $empty = $this->get('/api/gestion/statistiques?from=2011-05-01&to=2011-05-31');
        $this->assertSame(['site', null], [$empty['lastYear']['source'], $empty['indicators']['revenue']['lastYear']]);

        // L'ANNÉE 2011 : l'an dernier est réduit aux VENTES de l'archive, mois par mois ; le reste est vide ; marqué
        $year = $this->get('/api/gestion/statistiques/annee?year=2011&locale=fr');
        $this->assertSame([OrderArchive::SOURCE, 2010], [$year['lastYear']['source'], $year['lastYear']['year']]);
        $this->assertStringEndsWith('(avant la migration)', $year['lastYear']['label']);
        $months = array_column($year['lastYear']['months'], null, 'month');
        $this->assertSame([20000, 5000, 3000, 0], [$months[3]['sales'], $months[6]['sales'], $months[4]['sales'], $months[5]['sales']]);
        $this->assertSame([null, null], [$months[3]['grossMargin'], $months[3]['result']]);
        // la forme d'un mois ne change pas : un sous-tableau garde ses clés, vides
        $this->assertSame([true, null, true, null], [is_array($months[3]['otherRevenue']), $months[3]['otherRevenue']['total'], is_array($months[3]['variableCosts']), $months[3]['variableCosts']['total']]);
        $this->assertSame(['sales' => 28000, 'grossMargin' => null, 'result' => null], array_intersect_key(end($year['lastYear']['cumulative']), ['sales' => 1, 'grossMargin' => 1, 'result' => 1]));
        $this->assertContains('LAST_YEAR_BEFORE_MIGRATION', array_column($year['caveats'], 'code'));
        $this->assertSame(0, $year['totals']['sales'], 'les ventes d\'avant n\'entrent pas dans l\'année du site');
        // la console IA et les bilans PDF ne reçoivent PAS un an dernier venu de l'archive : ils ne savent pas le marquer
        $analysis = static::getContainer()->get(\App\Gestion\Analysis\AnalysisCatalog::class);
        $figures = array_column($analysis->run('year.overview', ['year' => 2011], 'fr')['figures'], null, 'id');
        $this->assertSame([false, false, false], [isset($figures['sales']['lastYear']), isset($figures['grossMargin']['lastYear']), isset($figures['netResult']['lastYear'])]);

        // Un RETOUR seul du site sur la plage de l'an dernier (avril 2010) : c'est une ligne de vente du site, l'archive n'est plus lue
        $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, purchase_price) VALUES (nextval('product_id_seq'), 'Vraie vente', 2500, false, 0, NOW(), ?, 'standard', 1000)", ["imp3-test-v-$t"]);
        $variant = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, (int) $conn->fetchOne('SELECT id FROM product WHERE slug = ?', ["imp3-test-v-$t"]))])->getId();
        $sell = function (string $day, int $type, int $status) use ($variant): void {
            $at = (new \DateTimeImmutable("$day 12:00", static::getContainer()->get(StoreClock::class)->timezone()))->setTimezone(new \DateTimeZone('UTC'));
            $order = $this->create(Order::class, ['totalAmount' => 1.0, 'reference' => 'IMP3-TEST'], function (Order $o, EntityManagerInterface $em) use ($at, $type, $status): void {
                $o->setOrderDate(\DateTime::createFromImmutable($at))->setStatus($em->find(StatusCommande::class, $status))->setOrderType($em->find(OrderType::class, $type))->setOrderSource($em->find(OrderSource::class, 2))->setUserId($em->find(User::class, $this->adminId));
            });
            $this->create(OrderItems::class, ['quantity' => 1, 'unitPrice' => 2500.0, 'totalPrice' => 2500.0], function (OrderItems $item, EntityManagerInterface $em) use ($order, $variant): void {
                $item->setOrderAssociated($em->find(Order::class, $order->getId()))->setProductVariant($em->find(ProductVariant::class, $variant));
            });
            $this->endOfWrite();
        };
        $this->assertSame(OrderArchive::SOURCE, $this->get('/api/gestion/statistiques?from=2011-04-01&to=2011-04-30')['lastYear']['source']);
        $sell('2010-04-15', 2, 6);
        $returned = $this->get('/api/gestion/statistiques?from=2011-04-01&to=2011-04-30');
        $this->assertSame(['site', null], [$returned['lastYear']['source'], $returned['indicators']['revenue']['lastYear']], 'le retour du site ne disparaît pas derrière l\'archive');
        $this->assertSame('site', $this->get('/api/gestion/statistiques/annee?year=2011')['lastYear']['source'] ?? 'site', 'même règle pour l\'année');

        // Dès que le site a UNE vraie vente sur la plage de l'an dernier, l'archive n'est plus lue : jamais de mélange
        $sell('2010-03-15', 1, 3);
        $sell('2010-03-16', 1, 3); // deux ventes : le retour d'avril ne ramène pas l'exercice à zéro
        $real = $this->get('/api/gestion/statistiques?from=2011-03-01&to=2011-03-31&locale=fr');
        $this->assertSame(['site', 2], [$real['lastYear']['source'], $real['indicators']['orders']['lastYear']]);
        $this->assertLessThan(20000, $real['indicators']['revenue']['lastYear'], 'la vraie vente seule, sans les 200 de l\'archive');
        $this->assertStringEndsNotWith('(avant la migration)', $real['comparison']['lastYearLabel']);
        $this->assertNotContains('LAST_YEAR_BEFORE_MIGRATION', array_column($real['caveats'], 'code'));
        $figures = array_column($analysis->run('year.overview', ['year' => 2011], 'fr')['figures'], null, 'id');
        $this->assertGreaterThan(0, $figures['sales']['lastYear'] ?? 0, 'un exercice terminé se compare à l\'exercice précédent du site (le cumul de son dernier mois)');
        $yearReal = $this->get('/api/gestion/statistiques/annee?year=2011');
        $this->assertSame('site', $yearReal['lastYear']['source']);
        $this->assertNotContains('LAST_YEAR_BEFORE_MIGRATION', array_column($yearReal['caveats'], 'code'));
        $this->assertNotNull(array_column($yearReal['lastYear']['months'], null, 'month')[3]['grossMargin'], 'les mois de l\'an dernier sont ceux du site, marge comprise');

        // Un invité d'import effacé (délai passé, ou annulation de l'import des clients) : ses commandes d'avant ne le tiennent pas
        $guest = $this->account("guest-imp3-$t@example.invalid", 'Invitée', []);
        $conn->executeStatement('UPDATE "user" SET signup_channel = ?, is_verified = false, password_set = false WHERE id = ?', [User::SIGNUP_IMPORT_INVITE, $guest]);
        $conn->executeStatement("INSERT INTO import_customer_invitation (user_id, job_id, state, erase_at) VALUES (?, 0, 'imported', NOW() - INTERVAL '1 day')", [$guest]);
        $conn->executeStatement('UPDATE import_order SET user_id = ? WHERE ref = ?', [$guest, "#A1-$t"]);
        $this->assertSame('erased', static::getContainer()->get(ImportGuests::class)->erase($guest));
        $this->assertSame([0, null, 'paid'], [(int) $conn->fetchOne('SELECT COUNT(*) FROM "user" WHERE id = ?', [$guest]), $conn->fetchOne('SELECT user_id FROM import_order WHERE ref = ?', ["#A1-$t"]), $conn->fetchOne('SELECT state FROM import_order WHERE ref = ?', ["#A1-$t"])]);
    }

    private function runImport(int $id): void
    {
        $this->em();
        static::getContainer()->get(OrderImportService::class)->run($id, 'test');
        $this->em()->clear();
        $this->endOfWrite();
    }

    private function wipe(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement("DELETE FROM order_items WHERE order_associated_id IN (SELECT id FROM \"order\" WHERE reference = 'IMP3-TEST')");
        $conn->executeStatement("DELETE FROM \"order\" WHERE reference = 'IMP3-TEST'");
        $conn->executeStatement("DELETE FROM product_variant WHERE product_id IN (SELECT id FROM product WHERE slug LIKE 'imp3-test-%')");
        $conn->executeStatement("DELETE FROM product WHERE slug LIKE 'imp3-test-%'");
        $conn->executeStatement('DELETE FROM import_order');
        $conn->executeStatement("DELETE FROM import_customer_invitation WHERE user_id IN (SELECT id FROM \"user\" WHERE email LIKE '%-imp3-%@example.invalid')");
        $conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE '%-imp3-%@example.invalid' OR email LIKE 'import-commandes-%@example.invalid' OR (firstname = 'Compte' AND lastname = 'supprimé' AND deleted_at > NOW() - INTERVAL '1 hour')");
        $conn->executeStatement("DELETE FROM content_audit_log WHERE resource = 'import_job'");
        $conn->executeStatement('DELETE FROM import_job');
        foreach (glob(static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/private_media/imports/' . MV_TEST_TENANT_CODE . '/*.csv') ?: [] as $csv) {
            @unlink($csv); // les fichiers déposés par le test
        }
        static::getContainer()->get(CacheInvalidationSubscriber::class)->invalidateWrittenTenants();
    }

    private function upload(string $content, string $kind): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, $content);
        $this->files[] = $path;
        $this->client->request('POST', '/api/site/imports', ['source' => 'shopify', 'kind' => $kind], ['file' => new UploadedFile($path, 'export-commandes.csv', 'text/csv', null, true)], $this->headers(false));
    }

    private function headers(bool $json = true): array
    {
        return array_filter(['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => $json ? 'application/json' : null, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => $this->token !== '' ? 'Bearer ' . $this->token : null]);
    }

    private function request(string $method, string $route, ?array $body = null): void
    {
        $this->client->request($method, $route, [], [], $this->headers(), $body === null ? null : json_encode($body));
    }

    private function get(string $route): array
    {
        $this->request('GET', $route);
        $this->assertSame(200, $this->status(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 400));

        return $this->body();
    }

    private function status(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    private function body(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
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

    /** Un compte du site ; rend son identifiant */
    private function account(string $email, string $firstname, array $roles): int
    {
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname($firstname)->setLastname('Du Site')->setRoles($roles)->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->endOfWrite();

        return (int) $user->getId();
    }

    private function tokenFor(array $roles): string
    {
        $id = $this->account('import-commandes-' . bin2hex(random_bytes(3)) . '@example.invalid', 'Irène', $roles);
        if (in_array('ROLE_ADMIN', $roles, true)) {
            $this->adminId = $id;
        }

        return static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($this->em()->find(User::class, $id));
    }
}
