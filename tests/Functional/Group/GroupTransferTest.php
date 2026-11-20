<?php

namespace App\Tests\Functional\Group;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\User;
use App\Services\Group\Transfer\GroupTransferService;
use App\Services\Group\Transfer\GroupTransferSiteWriter;
use App\Services\Platform\TenantDatabaseConnector;
use App\Services\TenantCacheService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeLandingAiClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot G-5 (19/11/2026, contrat validé par le chef, 3 conditions) : le transfert léger entre boutiques d'un groupe.
 * - Le directeur DEMANDE ; la boutique source ENVOIE (la sortie de stock d'abord, la commande de destination ensuite) ; la destination reçoit.
 * - Refus : stock court (rien n'est sorti), article non copié, devises différentes, boutique associée sans lien, non-directeur, directeur entré.
 * - Panne entre les deux écritures : le stock revient chez la source, `send_failed` ; le renvoi réussit sans doublon.
 * - Rejeu : une sortie rejouée n'écrit rien de plus ; un envoi bloqué en `sending` est repris sans seconde sortie.
 * - Double clic : un envoi en cours ou déjà fait est refusé.
 * - Annulation : avant l'envoi, simple fermeture ; après l'envoi, commande fermée et stock rendu ; après une réception commencée, refusée.
 */
class GroupTransferTest extends WebTestCase
{
    private KernelBrowser $client;
    private \PDO $master;
    private string $tag;
    /** @var array<string, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        $_ENV['MAILER_DSN'] = $_SERVER['MAILER_DSN'] = 'null://plateforme-envoi%40example.invalid:x@default';
        $this->client = static::createClient();
        $this->master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $this->tag = bin2hex(random_bytes(3));
        $this->clean();
        $this->tokens = [
            'siege' => $this->token(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'siege', ['ROLE_ADMIN'], 'Hélène'),
            'boutique' => $this->token(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'boutique', ['ROLE_ADMIN'], 'Marc'),
            'employe' => $this->token(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'employe', ['ROLE_USER'], 'Léa'),
            'plateforme' => $this->token(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'plateforme', ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], 'Plateforme'),
        ];
    }

    protected function tearDown(): void
    {
        $this->clean();
        unset($_ENV['MAILER_DSN'], $_SERVER['MAILER_DSN']);
        parent::tearDown();
    }

    public function testRequestSendReceiveAndTheRefusals(): void
    {
        [$groupId, $headVariant, $shopVariant] = $this->setUpGroup('created');
        // refus de la demande
        $this->assertSame([403, 'GROUP_TRANSFER_FORBIDDEN'], $this->refusal('POST', '/api/gestion/groupe/transferts', $this->body($headVariant, 2), 'boutique'), 'pas le directeur');
        $this->assertSame([422, 'VALIDATION_FAILED'], $this->refusal('POST', '/api/gestion/groupe/transferts', ['source' => MV_TEST_TENANT_CODE, 'target' => MV_TEST_TENANT2_CODE, 'lines' => [['variantId' => $headVariant, 'quantity' => 0]]], 'siege'));
        $this->assertSame([422, 'TRANSFER_ITEM_NOT_IN_TARGET'], $this->refusal('POST', '/api/gestion/groupe/transferts', $this->body($this->variant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'non copié', 5), 1), 'siege'), 'copiez d\'abord l\'article');
        $this->pdo(MV_TEST_TENANT2_DB)->exec("UPDATE entreprise SET currency = 'EUR'");
        $this->assertSame([409, 'TRANSFER_CURRENCY_DIFFERS'], $this->refusal('POST', '/api/gestion/groupe/transferts', $this->body($headVariant, 1), 'siege'));
        $this->pdo(MV_TEST_TENANT2_DB)->exec("UPDATE entreprise SET currency = 'CAD'");

        // demande : rien ne bouge dans le stock ; la source est prévenue
        $transfer = $this->call('POST', '/api/gestion/groupe/transferts', $this->body($headVariant, 3), 'siege', 201);
        $this->assertSame(['requested', 7], [$transfer['state'], $this->stock(MV_TEST_TENANT_DB, $headVariant)]);
        $this->assertContains("g5-siege-{$this->tag}@example.invalid", $this->mailedTo(), 'les administrateurs de la source (ici le siège) sont prévenus');

        // l'envoi est le geste de la SOURCE : le siège ici, depuis ses écrans de boutique ; jamais le compte de la plateforme
        $send = '/api/site/group-transfers/' . $transfer['id'] . '/send';
        $this->assertSame([404, 'TRANSFER_NOT_FOUND'], $this->refusal('POST', $send, null, 'boutique'), 'la destination n\'envoie pas');
        $sent = $this->call('POST', $send, null, 'siege', 200);
        $this->assertSame('sent', $sent['state']);
        $this->assertSame(4, $this->stock(MV_TEST_TENANT_DB, $headVariant), 'le stock sort chez la source');
        $this->assertSame(0, $this->stock(MV_TEST_TENANT2_DB, $shopVariant), 'il n\'entre chez la destination qu\'à la réception');
        $this->assertArrayNotHasKey('unitCostCents', $sent['lines'][0], 'la source ne relit pas le coût dans sa réponse');
        $this->assertSame(1234, $this->call('GET', '/api/gestion/groupe/transferts/' . $transfer['id'], null, 'siege', 200)['lines'][0]['unitCostCents'], 'coût de la source figé à l\'envoi, lu par le directeur');
        $order = $this->pdo(MV_TEST_TENANT2_DB)->query('SELECT c.name, l.quantity_ordered, l.unit_cost_cents, l.source FROM commande c JOIN commande_line l ON l.commande_id = c.id WHERE c.id = ' . (int) $sent['targetOrderId'])->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame([3, 1234, 'transfer'], [(int) $order['quantity_ordered'], (int) $order['unit_cost_cents'], $order['source']]);
        $this->assertStringStartsWith('Transfert depuis', (string) $order['name']);
        $this->assertSame([409, 'TRANSFER_ALREADY_SENT'], $this->refusal('POST', $send, null, 'siege'), 'double clic : une seule sortie');
        $this->assertSame(4, $this->stock(MV_TEST_TENANT_DB, $headVariant));

        // réception partielle : l'annulation est refusée ; réception complète : `received`, relu à la lecture
        $this->pdo(MV_TEST_TENANT2_DB)->exec('UPDATE commande_line SET quantity_received = 1 WHERE commande_id = ' . (int) $sent['targetOrderId']);
        $this->assertSame([409, 'TRANSFER_PARTLY_RECEIVED'], $this->refusal('POST', '/api/gestion/groupe/transferts/' . $transfer['id'] . '/cancel', null, 'siege'));
        $this->pdo(MV_TEST_TENANT2_DB)->exec('UPDATE commande_line SET quantity_received = quantity_ordered WHERE commande_id = ' . (int) $sent['targetOrderId']);
        $this->assertSame('received', $this->call('GET', '/api/gestion/groupe/transferts/' . $transfer['id'], null, 'siege', 200)['state']);
        $this->assertSame([409, 'TRANSFER_RECEIVED'], $this->refusal('POST', '/api/gestion/groupe/transferts/' . $transfer['id'] . '/cancel', null, 'siege'));

        // stock court : rien n'est sorti, la demande reste à envoyer
        $big = $this->call('POST', '/api/gestion/groupe/transferts', $this->body($headVariant, 50), 'siege', 201);
        $refused = $this->raw('POST', '/api/site/group-transfers/' . $big['id'] . '/send', null, 'siege');
        $this->assertSame(409, $refused->getStatusCode());
        $this->assertSame('TRANSFER_STOCK_SHORT', json_decode((string) $refused->getContent(), true)['code']);
        $this->assertSame([['variantId' => $headVariant, 'requested' => 50, 'available' => 4]], json_decode((string) $refused->getContent(), true)['lines']);
        $this->assertSame([4, 'requested'], [$this->stock(MV_TEST_TENANT_DB, $headVariant), $this->state((int) $big['id'])]);
    }

    public function testCancelBeforeAndAfterSendingAndTheAssociatedShop(): void
    {
        [$groupId, $headVariant] = $this->setUpGroup('created');
        $first = $this->call('POST', '/api/gestion/groupe/transferts', $this->body($headVariant, 2), 'siege', 201);
        $this->assertSame('cancelled', $this->call('POST', '/api/gestion/groupe/transferts/' . $first['id'] . '/cancel', null, 'siege', 200)['state'], 'avant l\'envoi : simple fermeture');
        $this->assertSame([409, 'TRANSFER_CANCELLED'], $this->refusal('POST', '/api/site/group-transfers/' . $first['id'] . '/send', null, 'siege'));

        $second = $this->call('POST', '/api/gestion/groupe/transferts', $this->body($headVariant, 2), 'siege', 201);
        $sent = $this->call('POST', '/api/site/group-transfers/' . $second['id'] . '/send', null, 'siege', 200);
        $this->assertSame(5, $this->stock(MV_TEST_TENANT_DB, $headVariant));
        $this->assertSame('cancelled', $this->call('POST', '/api/gestion/groupe/transferts/' . $second['id'] . '/cancel', null, 'siege', 200)['state']);
        $this->assertSame(7, $this->stock(MV_TEST_TENANT_DB, $headVariant), 'après l\'envoi : le stock revient chez la source');
        $this->assertSame([1, 0], array_map('intval', $this->pdo(MV_TEST_TENANT2_DB)->query('SELECT c.is_closed::int, SUM(l.quantity_ordered) FROM commande c JOIN commande_line l ON l.commande_id = c.id WHERE c.id = ' . (int) $sent['targetOrderId'] . ' GROUP BY c.is_closed')->fetch(\PDO::FETCH_NUM)), 'la commande de destination est fermée, sans quantité');

        // une boutique ASSOCIÉE sans le lien « même entreprise » : refusée ; le lien la permet ; son retrait annule les transferts demandés
        $this->master->exec("UPDATE shop_group_sites SET origin = 'associated' WHERE tenant_code = '" . MV_TEST_TENANT2_CODE . "'");
        $this->assertSame([422, 'TRANSFER_ASSOCIATED_SHOP'], $this->refusal('POST', '/api/gestion/groupe/transferts', $this->body($headVariant, 1), 'siege'));
        $this->master->exec("UPDATE shop_group_sites SET same_company = TRUE, same_company_since = NOW() WHERE tenant_code = '" . MV_TEST_TENANT2_CODE . "' AND left_at IS NULL");
        $third = $this->call('POST', '/api/gestion/groupe/transferts', $this->body($headVariant, 1), 'siege', 201);
        $this->call('DELETE', '/api/site/group-same-company', null, 'boutique', 200);
        $this->assertSame('cancelled', $this->state((int) $third['id']), 'lien retiré : le transfert seulement demandé est annulé');
        $this->assertSame([422, 'TRANSFER_ASSOCIATED_SHOP'], $this->refusal('POST', '/api/gestion/groupe/transferts', $this->body($headVariant, 1), 'siege'));
    }

    public function testAFailureBetweenTheTwoWritesGivesTheStockBackAndAResendDoesNotDouble(): void
    {
        [$groupId, $headVariant] = $this->setUpGroup('created');
        $transfer = $this->call('POST', '/api/gestion/groupe/transferts', $this->body($headVariant, 3), 'siege', 201);
        // la destination refuse la LIGNE de la commande (contrainte temporaire) : la commande est écrite puis défaite (même transaction), le
        // flush en échec ferme l'EntityManager (rouvert), la compensation rend le stock
        $target = $this->pdo(MV_TEST_TENANT2_DB);
        $target->exec("ALTER TABLE commande_line ADD CONSTRAINT g5_panne CHECK (source <> 'transfer') NOT VALID");
        try {
            $failed = $this->raw('POST', '/api/site/group-transfers/' . $transfer['id'] . '/send', null, 'siege');
        } finally {
            $target->exec('ALTER TABLE commande_line DROP CONSTRAINT IF EXISTS g5_panne');
        }
        $this->assertSame([503, 'TRANSFER_SEND_FAILED'], [$failed->getStatusCode(), json_decode((string) $failed->getContent(), true)['code'] ?? null]);
        $this->assertSame(['send_failed', 7], [$this->state((int) $transfer['id']), $this->stock(MV_TEST_TENANT_DB, $headVariant)], 'le stock est revenu chez la source');
        $this->assertSame(0, (int) $target->query("SELECT COUNT(*) FROM commande WHERE name LIKE 'Transfert depuis%'")->fetchColumn(), 'aucune commande à moitié chez la destination');
        $this->assertSame(1, (int) $this->master->query("SELECT COUNT(*) FROM platform_incident WHERE route = 'group_transfer'")->fetchColumn(), 'la plateforme est prévenue');

        // renvoi : il réussit, une seule sortie nette
        $this->assertSame('sent', $this->call('POST', '/api/site/group-transfers/' . $transfer['id'] . '/send', null, 'siege', 200)['state']);
        $this->assertSame(4, $this->stock(MV_TEST_TENANT_DB, $headVariant));
        $this->assertSame(1, (int) $target->query("SELECT COUNT(*) FROM commande WHERE name LIKE 'Transfert depuis%'")->fetchColumn());
    }

    public function testAReplayedExitWritesNothingMoreAndAStuckSendingIsResumed(): void
    {
        [$groupId, $headVariant] = $this->setUpGroup('created');
        $transfer = $this->call('POST', '/api/gestion/groupe/transferts', $this->body($headVariant, 2), 'siege', 201);
        // la clé des marques de sortie : le transfert ET sa tentative d'envoi (la reprise garde la tentative 1)
        $uid = (string) $this->master->query('SELECT uid FROM shop_group_transfers WHERE id = ' . (int) $transfer['id'])->fetchColumn() . '#1';
        $writer = static::getContainer()->get(GroupTransferSiteWriter::class);
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $lines = [['line_no' => 1, 'source_variant_id' => $headVariant, 'quantity' => 2]];
        // panne simulée APRÈS la sortie et AVANT la commande : l'état reste `sending`, la sortie est faite
        $this->master->exec("UPDATE shop_group_transfers SET state = 'sending', attempt = 1, sending_since = NOW() - INTERVAL '10 minutes' WHERE id = " . (int) $transfer['id']);
        $this->assertFalse($writer->sendOut($uid, $lines)['replayed']);
        $this->assertTrue($writer->sendOut($uid, $lines)['replayed'], 'rejouée : rien de plus');
        $this->assertSame(5, $this->stock(MV_TEST_TENANT_DB, $headVariant));
        // double clic pendant l'envoi
        $this->assertSame([409, 'TRANSFER_ALREADY_SENDING'], $this->refusal('POST', '/api/site/group-transfers/' . $transfer['id'] . '/send', null, 'siege'));
        // la reprise termine l'envoi sans seconde sortie
        $this->assertSame(1, static::getContainer()->get(GroupTransferService::class)->resume());
        $this->assertSame(['sent', 5], [$this->state((int) $transfer['id']), $this->stock(MV_TEST_TENANT_DB, $headVariant)]);
        $this->assertSame(1, (int) $this->pdo(MV_TEST_TENANT2_DB)->query("SELECT COUNT(*) FROM commande WHERE name LIKE 'Transfert depuis%'")->fetchColumn());
    }

    public function testOnlyTheShopAdministratorSendsNeverAnEnteredDirectorNorThePlatform(): void
    {
        [$groupId, , , $shopToHead] = $this->setUpGroup('created');
        // un transfert de la boutique vers le siège : c'est l'administrateur de la BOUTIQUE qui envoie
        $transfer = $this->call('POST', '/api/gestion/groupe/transferts', ['source' => MV_TEST_TENANT2_CODE, 'target' => MV_TEST_TENANT_CODE, 'lines' => [['variantId' => $shopToHead, 'quantity' => 1]]], 'siege', 201);
        $send = '/api/site/group-transfers/' . $transfer['id'] . '/send';
        $this->assertSame([403, 'GROUP_FORBIDDEN'], $this->refusal('POST', $send, null, 'plateforme'));
        $this->assertSame(403, $this->raw('POST', $send, null, 'employe')->getStatusCode());
        $this->assertSame([404, 'TRANSFER_NOT_FOUND'], $this->refusal('POST', $send, null, 'siege'), 'le directeur, chez lui, n\'est pas la source');
        $entered = json_decode((string) $this->raw('POST', '/api/me/contexts/enter', ['site' => MV_TEST_TENANT2_CODE], 'siege')->getContent(), true);
        $this->assertIsString($entered['token'] ?? null);
        $this->tokens['entre'] = (string) $entered['token'];
        $this->assertSame(403, $this->raw('POST', $send, null, 'entre')->getStatusCode(), 'le directeur entré dans la boutique n\'envoie jamais');
        $this->assertSame(403, $this->raw('GET', '/api/site/group-transfers', null, 'entre')->getStatusCode());
        $this->assertSame('requested', $this->state((int) $transfer['id']));
        $this->assertSame([(int) $transfer['id']], array_column($this->call('GET', '/api/site/group-transfers', null, 'boutique', 200)['transfers'], 'id'));
        $this->assertSame('sent', $this->call('POST', $send, null, 'boutique', 200)['state']);
    }

    /** Relecture G-5, passe 1 : la reprise laisse un envoi VIVANT (verrou tenu ailleurs) ; elle termine un envoi dont la commande existe déjà */
    public function testTheResumeLeavesALiveSendingAndFinishesWhenTheOrderAlreadyExists(): void
    {
        [$groupId, $headVariant] = $this->setUpGroup('created');
        $transfer = $this->call('POST', '/api/gestion/groupe/transferts', $this->body($headVariant, 2), 'siege', 201);
        $id = (int) $transfer['id'];
        $uid = (string) $this->master->query("SELECT uid FROM shop_group_transfers WHERE id = $id")->fetchColumn();
        $writer = static::getContainer()->get(GroupTransferSiteWriter::class);
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        // panne APRÈS les deux écritures, AVANT « envoyé » ; le coût n'a pas été écrit
        $this->master->exec("UPDATE shop_group_transfers SET state = 'sending', attempt = 1, sending_since = NOW() - INTERVAL '10 minutes' WHERE id = $id");
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $writer->sendOut($uid . '#1', [['line_no' => 1, 'source_variant_id' => $headVariant, 'quantity' => 2]]);
        $lines = $this->master->query("SELECT line_no, target_product_id, target_variant_id, quantity FROM shop_group_transfer_lines WHERE transfer_id = $id")->fetchAll(\PDO::FETCH_ASSOC);
        $provider->switchTenant(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE);
        $writer->createOrder($uid, 'Siège', array_map(static fn (array $l): array => ['line_no' => (int) $l['line_no'], 'target_product_id' => (int) $l['target_product_id'], 'target_variant_id' => (int) $l['target_variant_id'], 'quantity' => (int) $l['quantity'], 'unit_cost_cents' => null], $lines), 'essai');
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        // un envoi vivant tient le verrou (une autre connexion à la base maître) : la reprise le laisse
        $other = $this->otherMaster();
        $other->query('SELECT pg_advisory_lock(' . \App\Services\Group\Transfer\GroupTransferRepository::LOCK_SPACE . ", $id)");
        $this->assertSame(0, static::getContainer()->get(GroupTransferService::class)->resume());
        $this->assertSame([409, 'TRANSFER_SENDING'], $this->refusal('POST', "/api/gestion/groupe/transferts/$id/cancel", null, 'siege'), 'pas d\'annulation pendant un envoi vivant');
        $other->query('SELECT pg_advisory_unlock(' . \App\Services\Group\Transfer\GroupTransferRepository::LOCK_SPACE . ", $id)");

        // verrou libre : la reprise voit la commande déjà écrite, passe à « envoyé », sans seconde commande ni seconde sortie ; le coût est posé
        $this->assertSame(1, static::getContainer()->get(GroupTransferService::class)->resume());
        $this->assertSame(['sent', 5], [$this->state($id), $this->stock(MV_TEST_TENANT_DB, $headVariant)]);
        $this->assertSame(1, (int) $this->pdo(MV_TEST_TENANT2_DB)->query("SELECT COUNT(*) FROM commande WHERE name LIKE 'Transfert depuis%'")->fetchColumn());
        $this->assertSame(1234, (int) $this->master->query("SELECT unit_cost_cents FROM shop_group_transfer_lines WHERE transfer_id = $id")->fetchColumn(), 'coût posé à la reprise');
    }

    /** Relecture G-5, passe 1 : annuler un envoi dont la destination a quitté le groupe annule AUSSI sa commande ; associée en source refusée ; directeur entré refusé */
    public function testCancelWhenTheTargetLeftAndTheOtherRefusals(): void
    {
        [$groupId, $headVariant, , $shopToHead] = $this->setUpGroup('created');
        $transfer = $this->call('POST', '/api/gestion/groupe/transferts', $this->body($headVariant, 3), 'siege', 201);
        $sent = $this->call('POST', '/api/site/group-transfers/' . $transfer['id'] . '/send', null, 'siege', 200);
        $this->master->exec("UPDATE shop_group_sites SET left_at = NOW() WHERE tenant_code = '" . MV_TEST_TENANT2_CODE . "'");
        $this->assertSame('cancelled', $this->call('POST', '/api/gestion/groupe/transferts/' . $transfer['id'] . '/cancel', null, 'siege', 200)['state']);
        $this->assertSame(7, $this->stock(MV_TEST_TENANT_DB, $headVariant), 'stock rendu');
        $this->assertSame([1, 0], array_map('intval', $this->pdo(MV_TEST_TENANT2_DB)->query('SELECT c.is_closed::int, SUM(l.quantity_ordered) FROM commande c JOIN commande_line l ON l.commande_id = c.id WHERE c.id = ' . (int) $sent['targetOrderId'] . ' GROUP BY c.is_closed')->fetch(\PDO::FETCH_NUM)), 'la commande de la boutique sortie est annulée aussi');

        // de retour dans le groupe, ASSOCIÉE sans lien, en SOURCE : refusée
        $this->master->exec("INSERT INTO shop_group_sites (group_id, tenant_code, origin) VALUES ($groupId, '" . MV_TEST_TENANT2_CODE . "', 'associated')");
        $this->assertSame([422, 'TRANSFER_ASSOCIATED_SHOP'], $this->refusal('POST', '/api/gestion/groupe/transferts', ['source' => MV_TEST_TENANT2_CODE, 'target' => MV_TEST_TENANT_CODE, 'lines' => [['variantId' => $shopToHead, 'quantity' => 1]]], 'siege'));
        // le directeur entré dans une boutique ne demande pas
        $entered = json_decode((string) $this->raw('POST', '/api/me/contexts/enter', ['site' => MV_TEST_TENANT2_CODE], 'siege')->getContent(), true);
        $this->tokens['entre'] = (string) ($entered['token'] ?? '');
        $this->assertSame(403, $this->raw('POST', '/api/gestion/groupe/transferts', $this->body($headVariant, 1), 'entre')->getStatusCode());
    }

    /** Relecture G-5, passe 2 : la commande d'un transfert se reçoit mais ne se modifie pas ; un envoi bloqué (verrou libre) s'annule entièrement */
    /** Lot G-5c (demande de MAUI) : l'administrateur voit ce qu'il envoie (noms lus dans la source), les noms des sites, et la marque de la commande d'arrivée */
    public function testTheLinesCarryTheNamesReadInTheSourceAndTheArrivalOrderIsMarked(): void
    {
        [, $headVariant] = $this->setUpGroup('created');
        $transfer = $this->call('POST', '/api/gestion/groupe/transferts', $this->body($headVariant, 2), 'siege', 201);
        $name = (string) $this->pdo(MV_TEST_TENANT_DB)->query("SELECT p.name FROM product_variant v JOIN product p ON p.id = v.product_id WHERE v.id = $headVariant")->fetchColumn();
        $product = (int) $this->pdo(MV_TEST_TENANT_DB)->query("SELECT product_id FROM product_variant WHERE id = $headVariant")->fetchColumn();
        $names = fn (string $code): string => (string) $this->master->query("SELECT name FROM tenants WHERE code = '$code'")->fetchColumn();

        $toSend = array_column($this->call('GET', '/api/site/group-transfers', null, 'siege', 200)['transfers'], null, 'id')[$transfer['id']];
        $this->assertSame([MV_TEST_TENANT_CODE, MV_TEST_TENANT2_CODE, $names(MV_TEST_TENANT_CODE), $names(MV_TEST_TENANT2_CODE)], [$toSend['source'], $toSend['target'], $toSend['sourceName'], $toSend['targetName']]);
        $line = $toSend['lines'][0];
        $this->assertSame([1, $headVariant, 2], [$line['line'], $line['sourceVariantId'], $line['quantity']], 'les champs d\'avant restent');
        $this->assertSame(['id' => $product, 'name' => $name], $line['product']);
        $this->assertSame($headVariant, $line['variant']['id']);
        $this->assertArrayHasKey('size', $line['variant']);
        $this->assertArrayNotHasKey('unitCostCents', $line, 'jamais de coût pour la source');
        $this->assertSame(['id' => $product, 'name' => $name], $this->call('GET', '/api/gestion/groupe/transferts/' . $transfer['id'], null, 'siege', 200)['lines'][0]['product'], 'le directeur aussi');

        $sent = $this->call('POST', '/api/site/group-transfers/' . $transfer['id'] . '/send', null, 'siege', 200);
        $rows = $this->call('GET', '/api/commandes/lignes/a-recevoir?page=1&limit=200', null, 'boutique', 200);
        $arrival = array_values(array_filter($rows['items'] ?? $rows, static fn (array $l): bool => ($l['commande']['id'] ?? null) === $sent['targetOrderId']));
        $this->assertNotSame([], $arrival);
        $this->assertSame([true, 'transfer'], [$arrival[0]['commande']['groupTransfer'], $arrival[0]['source']], 'la marque de la commande d\'arrivée, en plus de la source de la ligne');
    }

    public function testTheTransferOrderIsLockedAndAStuckSendingIsCancelled(): void
    {
        [$groupId, $headVariant] = $this->setUpGroup('created');
        $transfer = $this->call('POST', '/api/gestion/groupe/transferts', $this->body($headVariant, 2), 'siege', 201);
        $sent = $this->call('POST', '/api/site/group-transfers/' . $transfer['id'] . '/send', null, 'siege', 200);
        $order = (int) $sent['targetOrderId'];
        $line = (int) $this->pdo(MV_TEST_TENANT2_DB)->query("SELECT id FROM commande_line WHERE commande_id = $order")->fetchColumn();
        $period = (int) $this->pdo(MV_TEST_TENANT2_DB)->query("SELECT collections_id FROM commande WHERE id = $order")->fetchColumn();
        $listed = array_column($this->call('GET', "/api/collections/$period/commandes", null, 'boutique', 200), 'groupTransfer', 'id');
        $this->assertTrue($listed[$order] ?? null, 'la liste des commandes porte la marque : les écrans cachent Modifier et Supprimer');
        $this->assertSame([409, 'TRANSFER_ORDER_LOCKED'], $this->refusal('DELETE', "/api/commandes/$order/lignes/$line", null, 'boutique'));
        $this->assertSame([409, 'TRANSFER_ORDER_LOCKED'], $this->refusal('DELETE', "/api/commandes/$order", null, 'boutique'));
        $this->assertSame(1, (int) $this->pdo(MV_TEST_TENANT2_DB)->query("SELECT COUNT(*) FROM commande_line WHERE commande_id = $order")->fetchColumn(), 'rien n\'a bougé');

        // un second transfert, bloqué en envoi après sa sortie (panne), verrou libre : l'annulation rend le stock
        $stuck = $this->call('POST', '/api/gestion/groupe/transferts', $this->body($headVariant, 1), 'siege', 201);
        $id = (int) $stuck['id'];
        $uid = (string) $this->master->query("SELECT uid FROM shop_group_transfers WHERE id = $id")->fetchColumn();
        $this->master->exec("UPDATE shop_group_transfers SET state = 'sending', attempt = 1, sending_since = NOW() WHERE id = $id");
        static::getContainer()->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        static::getContainer()->get(GroupTransferSiteWriter::class)->sendOut($uid . '#1', [['line_no' => 1, 'source_variant_id' => $headVariant, 'quantity' => 1]]);
        $this->assertSame(4, $this->stock(MV_TEST_TENANT_DB, $headVariant));
        $this->assertSame('cancelled', $this->call('POST', "/api/gestion/groupe/transferts/$id/cancel", null, 'siege', 200)['state']);
        $this->assertSame(5, $this->stock(MV_TEST_TENANT_DB, $headVariant), 'la sortie de l\'envoi bloqué est rendue');
    }

    /** Mutation T7 : une tentative précédente dont la sortie n'a pas été rendue (compensation en panne) est rendue AVANT le renvoi */
    public function testAResendFirstGivesBackWhatAPreviousAttemptKept(): void
    {
        [$groupId, $headVariant] = $this->setUpGroup('created');
        $transfer = $this->call('POST', '/api/gestion/groupe/transferts', $this->body($headVariant, 2), 'siege', 201);
        $id = (int) $transfer['id'];
        $uid = (string) $this->master->query("SELECT uid FROM shop_group_transfers WHERE id = $id")->fetchColumn();
        // tentative 1 : la sortie est faite, la compensation n'a pas eu lieu (panne), l'état est « en échec d'envoi »
        static::getContainer()->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        static::getContainer()->get(GroupTransferSiteWriter::class)->sendOut($uid . '#1', [['line_no' => 1, 'source_variant_id' => $headVariant, 'quantity' => 2]]);
        $this->master->exec("UPDATE shop_group_transfers SET state = 'send_failed', attempt = 1 WHERE id = $id");
        $this->assertSame(5, $this->stock(MV_TEST_TENANT_DB, $headVariant));
        // le renvoi (tentative 2) rend d'abord la tentative 1, puis sort : une seule sortie nette
        $this->assertSame('sent', $this->call('POST', "/api/site/group-transfers/$id/send", null, 'siege', 200)['state']);
        $this->assertSame(5, $this->stock(MV_TEST_TENANT_DB, $headVariant), '7 − 2, jamais 7 − 4');
    }

    /** Contrat web § 10 : le directeur cherche les variantes de la source (sans coût) ; la fiche porte la quantité reçue par ligne */
    public function testTheDirectorSearchesTheSourceVariantsAndReadsTheReceivedQuantities(): void
    {
        [$groupId, $headVariant] = $this->setUpGroup('created');
        $found = $this->call('GET', '/api/gestion/groupe/boutiques/' . MV_TEST_TENANT_CODE . '/variantes?q=' . rawurlencode('siège ' . $this->tag) . '&target=' . MV_TEST_TENANT2_CODE, null, 'siege', 200)['variants'];
        $this->assertSame([$headVariant], array_column($found, 'variantId'));
        $this->assertSame([7, true], [$found[0]['stock'], $found[0]['copiedToTarget']], 'stock disponible ; déjà copié dans la destination');
        $this->assertArrayNotHasKey('cost', $found[0]);
        $this->assertStringNotContainsString('1234', (string) json_encode($found), 'jamais le coût');
        $this->assertSame([403, 'GROUP_TRANSFER_FORBIDDEN'], $this->refusal('GET', '/api/gestion/groupe/boutiques/' . MV_TEST_TENANT_CODE . '/variantes', null, 'boutique'), 'directeur seulement');
        $this->assertSame([404, 'GROUP_SHOP_UNKNOWN'], $this->refusal('GET', '/api/gestion/groupe/boutiques/ailleurs-' . $this->tag . '/variantes', null, 'siege'));
        $this->master->exec("UPDATE shop_group_sites SET origin = 'associated' WHERE tenant_code = '" . MV_TEST_TENANT2_CODE . "'");
        $this->assertSame([422, 'TRANSFER_ASSOCIATED_SHOP'], $this->refusal('GET', '/api/gestion/groupe/boutiques/' . MV_TEST_TENANT2_CODE . '/variantes', null, 'siege'), 'boutique de la même entreprise seulement');
        $this->master->exec("UPDATE shop_group_sites SET origin = 'created' WHERE tenant_code = '" . MV_TEST_TENANT2_CODE . "'");

        $transfer = $this->call('POST', '/api/gestion/groupe/transferts', $this->body($headVariant, 3), 'siege', 201);
        $this->assertNull($this->call('GET', '/api/gestion/groupe/transferts/' . $transfer['id'], null, 'siege', 200)['lines'][0]['receivedQuantity'], 'avant l\'envoi : rien à lire');
        $sent = $this->call('POST', '/api/site/group-transfers/' . $transfer['id'] . '/send', null, 'siege', 200);
        $this->pdo(MV_TEST_TENANT2_DB)->exec('UPDATE commande_line SET quantity_received = 1 WHERE commande_id = ' . (int) $sent['targetOrderId']);
        $this->assertSame(1, $this->call('GET', '/api/gestion/groupe/transferts/' . $transfer['id'], null, 'siege', 200)['lines'][0]['receivedQuantity']);
    }

    /** Volet IA : le directeur DEMANDE par l'assistant (confirmé par lui) ; la boutique lit ce qu'elle doit envoyer ; l'envoi n'est jamais un outil */
    public function testTheAssistantAsksATransferButNeverSendsIt(): void
    {
        [$groupId, $headVariant] = $this->setUpGroup('created');
        $action = $this->assistant([['propose_group_transfer', ['source' => MV_TEST_TENANT_CODE, 'target' => MV_TEST_TENANT2_CODE, 'lines' => [['variantId' => $headVariant, 'quantity' => 2]]]]], 'siege');
        $this->assertStringContainsString('la boutique source l\'envoie elle-même', $action['summary']);
        $this->assertSame(0, (int) $this->master->query('SELECT COUNT(*) FROM shop_group_transfers')->fetchColumn(), 'rien avant la confirmation');
        $this->call('POST', '/api/site/assistant/actions/' . $action['id'] . '/confirm', [], 'siege', 200);
        $this->assertSame('requested', (string) $this->master->query('SELECT state FROM shop_group_transfers')->fetchColumn());
        $this->assertSame(7, $this->stock(MV_TEST_TENANT_DB, $headVariant), 'rien ne sort : l\'envoi reste le geste de la boutique');
        $given = array_column(FakeLandingAiClient::$requests[0]['tools'], 'name');
        $transferTools = array_values(array_filter($given, static fn (string $t): bool => str_contains($t, 'transfer')));
        sort($transferTools);
        $this->assertSame(['get_group_transfers', 'get_transfers_to_send', 'propose_group_transfer'], $transferTools, 'lire et demander ; jamais envoyer ni recevoir');
    }

    /** @param list<array{0: string, 1: array<string, mixed>}> $uses @return array<string, mixed> l'action proposée */
    private function assistant(array $uses, string $who): array
    {
        FakeLandingAiClient::reset();
        $content = [];
        foreach ($uses as $i => [$name, $input]) {
            $content[] = (object) ['type' => 'tool_use', 'id' => 'tu_' . $i, 'name' => $name, 'input' => (object) $input];
        }
        FakeLandingAiClient::$queue[] = (object) ['content' => $content, 'usage' => (object) ['input_tokens' => 10, 'output_tokens' => 5]];
        FakeLandingAiClient::$queue[] = (object) ['content' => [(object) ['type' => 'text', 'text' => 'Voici.']], 'usage' => (object) ['input_tokens' => 10, 'output_tokens' => 5]];
        $body = $this->call('POST', '/api/site/assistant', ['messages' => [['role' => 'user', 'content' => 'Transfert']]], $who, 200);
        $this->assertCount(1, $body['actions'], (string) json_encode(FakeLandingAiClient::$requests[1]['messages'][2]['content'] ?? null));

        return $body['actions'][0];
    }

    private function otherMaster(): \PDO
    {
        $url = parse_url((string) ($_SERVER['MASTER_DATABASE_URL'] ?? $_ENV['MASTER_DATABASE_URL'] ?? getenv('MASTER_DATABASE_URL')));
        $db = (string) $this->master->query('SELECT current_database()')->fetchColumn();

        return new \PDO(sprintf('pgsql:host=%s;port=%d;dbname=%s', $url['host'], $url['port'] ?? 5432, $db), urldecode((string) $url['user']), urldecode((string) $url['pass']), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    /**
     * Un groupe : le siège (tenant 1) et la boutique (tenant 2) d'origine `$origin` ; un article copié du siège vers la boutique (lien G-4) et un
     * article de la boutique copié vers le siège.
     *
     * @return array{0: int, 1: int, 2: int, 3: int} groupe, variante du siège (stock 7), sa copie dans la boutique (stock 0), variante de la boutique copiée au siège
     */
    private function setUpGroup(string $origin): array
    {
        foreach ([[MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE], [MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE]] as [$db, $code]) {
            $this->pdo($db)->exec("UPDATE entreprise SET is_multi_shop_active = TRUE, currency = 'CAD'");
            static::getContainer()->get(TenantCacheService::class)->invalidateSite($code, $db);
        }
        $id = (int) $this->master->query("INSERT INTO shop_groups (code, name, head_tenant_code) VALUES ('groupe-g5-{$this->tag}', 'Siège', '" . MV_TEST_TENANT_CODE . "') RETURNING id")->fetchColumn();
        $this->master->exec("INSERT INTO shop_group_sites (group_id, tenant_code, origin) VALUES ($id, '" . MV_TEST_TENANT_CODE . "', 'head'), ($id, '" . MV_TEST_TENANT2_CODE . "', '$origin')");
        $headVariant = $this->variant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'siège', 7);
        $shopVariant = $this->variant(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'copie', 0);
        $shopOwn = $this->variant(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'boutique', 4);
        $headCopy = $this->variant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'copie boutique', 0);
        $copy = (int) $this->master->query("INSERT INTO shop_group_copies (group_id, source_tenant_code, target_tenant_code, target_job_id, articles, created_by) VALUES ($id, '" . MV_TEST_TENANT_CODE . "', '" . MV_TEST_TENANT2_CODE . "', 0, 1, 'essai') RETURNING id")->fetchColumn();
        $link = $this->master->prepare('INSERT INTO shop_group_item_links (group_id, copy_id, source_tenant_code, source_product_id, source_variant_id, target_tenant_code, target_product_id, target_variant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $link->execute([$id, $copy, MV_TEST_TENANT_CODE, $this->productOf(MV_TEST_TENANT_DB, $headVariant), $headVariant, MV_TEST_TENANT2_CODE, $this->productOf(MV_TEST_TENANT2_DB, $shopVariant), $shopVariant]);
        $link->execute([$id, $copy, MV_TEST_TENANT2_CODE, $this->productOf(MV_TEST_TENANT2_DB, $shopOwn), $shopOwn, MV_TEST_TENANT_CODE, $this->productOf(MV_TEST_TENANT_DB, $headCopy), $headCopy]);

        return [$id, $headVariant, $shopVariant, $shopOwn];
    }

    /** @return array<string, mixed> */
    private function body(int $variant, int $quantity): array
    {
        return ['source' => MV_TEST_TENANT_CODE, 'target' => MV_TEST_TENANT2_CODE, 'lines' => [['variantId' => $variant, 'quantity' => $quantity]]];
    }

    private function variant(string $db, string $code, string $label, int $stock): int
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant($db, $code);
        $em = $provider->getEntityManager();
        $product = (new Product())->setName("Transfert g5 $label {$this->tag}")->setDescription('<p>Un article</p>')->setMoreinformations(null)->setPrice(2500.0)->setCode('G5-' . bin2hex(random_bytes(3)))
            ->setSlug('transfert-g5-' . bin2hex(random_bytes(4)))->setTags(null)->setQuantity($stock)->setIsWeb(true)->setIsPos(true)->setIsLandingPage(false)->setSaleEnabled(true)->setRentalEnabled(false)
            ->setSubscriptionEnabled(false)->setIsbestseller(false)->setIsnewarrival(false)->setIsfeatured(false)->setIsspecialoffer(false)->setIsPreOrder(false)->setIsAccessory(false)
            ->setCustomizable(false)->setRequiresShipping(true)->setCreatedAt(new \DateTimeImmutable())->setPurchasePrice(1234.0);
        $em->persist($product);
        $variant = (new ProductVariant())->setStockQuantity($stock)->setPrice(2500);
        $product->addVariant($variant);
        $em->persist($variant);
        $em->flush();
        $id = (int) $variant->getId();
        $em->clear();
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $id;
    }

    private function productOf(string $db, int $variant): int
    {
        return (int) $this->pdo($db)->query("SELECT product_id FROM product_variant WHERE id = $variant")->fetchColumn();
    }

    private function stock(string $db, int $variant): int
    {
        return (int) $this->pdo($db)->query("SELECT stock_quantity FROM product_variant WHERE id = $variant")->fetchColumn();
    }

    private function state(int $id): string
    {
        return (string) $this->master->query("SELECT state FROM shop_group_transfers WHERE id = $id")->fetchColumn();
    }

    /** @return list<string> */
    private function mailedTo(): array
    {
        $to = [];
        foreach (self::getMailerMessages() as $mail) {
            foreach ($mail->getTo() as $address) {
                $to[] = $address->getAddress();
            }
        }

        return $to;
    }

    /** @return array{0: int, 1: ?string} */
    private function refusal(string $method, string $path, ?array $body, string $who): array
    {
        $response = $this->raw($method, $path, $body, $who);

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)['code'] ?? null];
    }

    /** @return array<string, mixed> */
    private function call(string $method, string $path, ?array $body, string $who, int $status): array
    {
        $response = $this->raw($method, $path, $body, $who);
        $this->assertSame($status, $response->getStatusCode(), $method . ' ' . $path . ' : ' . $response->getContent());

        return json_decode((string) $response->getContent(), true) ?? [];
    }

    private function raw(string $method, string $path, ?array $body, string $who): Response
    {
        $host = $who === 'siege' ? MV_TEST_TENANT_HOST : MV_TEST_TENANT2_HOST;
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . $host . $path, [], [], ['HTTP_X_TENANT_HOST' => $host, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokens[$who], 'REMOTE_ADDR' => '10.94.' . random_int(1, 254) . '.' . random_int(1, 254)], $body === null ? (in_array($method, ['POST', 'DELETE'], true) ? '{}' : null) : json_encode($body));

        return $this->client->getResponse();
    }

    /** @param list<string> $roles */
    private function token(string $db, string $code, string $name, array $roles, string $firstName): string
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant($db, $code);
        $em = $provider->getEntityManager();
        $email = "g5-$name-{$this->tag}@example.invalid";
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname($firstName)->setLastname('Essai')->setRoles($roles)->setPassword('inutilisable')->setIsVerified(true);
        $em->persist($user);
        $em->flush();
        $em->getConnection()->executeStatement("INSERT INTO refresh_tokens (id, refresh_token, username, valid) VALUES (nextval('refresh_tokens_id_seq'), ?, ?, NOW() + INTERVAL '1 day')", ['g5-' . bin2hex(random_bytes(30)), $email]);
        $token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $em->clear();
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $token;
    }

    private function clean(): void
    {
        foreach ([MV_TEST_TENANT_DB, MV_TEST_TENANT2_DB] as $db) {
            $pdo = $this->pdo($db);
            $ids = array_map('intval', $pdo->query("SELECT id FROM product WHERE name LIKE 'Transfert g5 %'")->fetchAll(\PDO::FETCH_COLUMN));
            $orders = array_map('intval', $pdo->query("SELECT id FROM commande WHERE name LIKE '%Transfert depuis%'")->fetchAll(\PDO::FETCH_COLUMN));
            if ($orders !== []) {
                $pdo->exec('DELETE FROM commande_line WHERE commande_id IN (' . implode(',', $orders) . ')');
                $pdo->exec('DELETE FROM commande WHERE id IN (' . implode(',', $orders) . ')');
            }
            if ($ids !== []) {
                $list = implode(',', $ids);
                $pdo->exec("DELETE FROM commande_line WHERE product_id IN ($list)");
                $pdo->exec("DELETE FROM inventory_movements WHERE product_variant_id IN (SELECT id FROM product_variant WHERE product_id IN ($list))");
                $pdo->exec("DELETE FROM product_variant WHERE product_id IN ($list)");
                $pdo->exec("DELETE FROM product WHERE id IN ($list)");
            }
            $pdo->exec("DELETE FROM fournisseur WHERE name LIKE 'Boutique %' AND NOT EXISTS (SELECT 1 FROM commande c WHERE c.fournisseur_id = fournisseur.id)");
            $pdo->exec('DELETE FROM group_transfer_marks');
            $pdo->exec("DELETE FROM content_audit_log WHERE resource = 'external_access'");
            $pdo->exec("DELETE FROM refresh_tokens WHERE username LIKE 'g5-%@example.invalid'");
            $pdo->exec("DELETE FROM \"user\" WHERE email LIKE 'g5-%@example.invalid'");
            $pdo->exec("UPDATE entreprise SET is_multi_shop_active = FALSE, currency = 'CAD'");
        }
        $this->master->exec("DELETE FROM site_entries WHERE mode = 'group'");
        $this->master->exec("DELETE FROM platform_incident_event WHERE incident_id IN (SELECT id FROM platform_incident WHERE route = 'group_transfer')");
        $this->master->exec("DELETE FROM platform_incident WHERE route = 'group_transfer'");
        foreach (['shop_group_transfer_events', 'shop_group_transfer_lines', 'shop_group_transfers', 'shop_group_item_links', 'shop_group_copies', 'shop_group_events', 'shop_group_invitations', 'shop_group_company_requests', 'shop_group_sites', 'shop_groups'] as $table) {
            $this->master->exec("DELETE FROM $table");
        }
        foreach ([[MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE], [MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE]] as [$db, $code]) {
            static::getContainer()->get(TenantCacheService::class)->invalidateSite($code, $db);
        }
    }

    private function pdo(string $db): \PDO
    {
        return static::getContainer()->get(TenantDatabaseConnector::class)->pdo($db);
    }
}
