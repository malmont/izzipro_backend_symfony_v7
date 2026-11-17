<?php

namespace App\Tests\Functional\LossPrevention;

use App\LossPrevention\Entity\LossBox;
use App\LossPrevention\Entity\LossPiece;
use App\LossPrevention\Entity\LossSaleUnit;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot L5-d (17/11/2026, `docs/prevention-pertes.md` § 18.2 et § 18.11) : l'alerte de sortie (pré-alerte, regroupée par zone et par minute,
 * jamais nominative, plafond propre) et les sorties permises que le boîtier lit pour décider seul du signal du portique. En essai, la file
 * `email` est synchrone : le contrôle part aussitôt la lecture écrite.
 */
class LossPreventionExitAlertTest extends LossPreventionTestCase
{
    private const BOXES = self::BASE . '/boitiers';
    private const MACHINE = 'machine-alerte-0123456789abcdef';
    /** @var array<string, string> lot L5-e : la machine d'un jeton qui n'est pas celle de `MACHINE` */
    private array $machines = [];

    private string $s;
    private string $ip;
    private int $n = 0;
    private string $token = '';
    private int $boxId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = strtoupper(bin2hex(random_bytes(3)));
        $this->ip = '10.' . random_int(1, 250) . '.' . random_int(1, 250) . '.' . random_int(1, 250);
        $this->flags(true, true);
        $this->db->executeStatement('UPDATE loss_setting SET opening_hours = NULL, exit_cursor = (SELECT COALESCE(MAX(id), 0) FROM loss_piece_event)');
        $this->db->executeStatement("DELETE FROM notification_log WHERE kind = 'loss_exit'");
    }

    protected function tearDown(): void
    {
        try {
            $this->db->executeStatement('UPDATE loss_setting SET opening_hours = NULL, exit_cursor = (SELECT COALESCE(MAX(id), 0) FROM loss_piece_event)');
            $this->db->executeStatement("UPDATE loss_box SET status = 'revoked', revoked_at = NOW() WHERE NOT exercise AND status <> 'revoked'");
            $this->db->executeStatement("UPDATE loss_device SET status = 'retired', cash_register_id = NULL WHERE NOT exercise AND status <> 'retired'");
            $this->db->beginTransaction();
            $this->provider->getEntityManager()->getRepository(LossPiece::class)->deleteExercise();
            $this->provider->getEntityManager()->getRepository(LossBox::class)->deleteExercise();
            $this->db->commit();
        } finally {
            parent::tearDown();
        }
    }

    public function testAnExitWithoutASaleAlertsTheManagersOnceAMinuteWithinTheirOwnCapAndNamesNobody(): void
    {
        $this->install();
        $variant = $this->article('Manteau', 50);
        $other = $this->article('Écharpe', 50);
        [$a, $b, $c] = [$this->tagged($variant, '01'), $this->tagged($variant, '02'), $this->tagged($other, '03')];
        // un téléphone inscrit : les notifications sont tentées pour de bon (statut compté par le plafond commun du téléphone)
        $this->json($this->admin('POST', '/api/notifications/appareils', ['token' => "jeton-$this->s", 'platform' => 'admin']), 201);
        $alerts = fn (): array => $this->json($this->admin('GET', self::BASE . '/alertes?locale=fr'));
        $pushes = fn (): array => $this->db->fetchAllAssociative("SELECT title, body, action, subject, status FROM notification_log WHERE kind = 'loss_exit' AND user_id = ? ORDER BY id", [$this->adminId]);

        // Une pièce non vendue passe la sortie : une alerte, une notification au gérant
        $this->assertSame(['accepted', null], $this->exit($a, 0));
        $list = $alerts();
        $this->assertSame(['day', 'exercise', 'alerts', 'pushedToday', 'cap', 'notice', 'serverTime'], array_keys($list));
        $this->assertCount(1, $list['alerts']);
        $alert = $list['alerts'][0];
        $this->assertSame(['id', 'at', 'atLabel', 'zone', 'zoneLabel', 'outOfHours', 'articles'], array_keys($alert));
        $this->assertSame(['exit', false, [['variantId' => $variant, 'name' => "Manteau L5D-$this->s", 'variantLabel' => null, 'count' => 1]]], [$alert['zone'], $alert['outOfHours'], $alert['articles']]);
        $this->assertSame([1, 3], [$list['pushedToday'], $list['cap']]);
        $push = $pushes();
        $this->assertCount(1, $push);
        $this->assertMatchesRegularExpression('/à vérifier|to check/u', mb_strtolower($push[0]['title']), 'dans la langue du site');
        // jamais nominative : ni l'alerte, ni la notification, ni le journal ne portent une personne
        $everything = json_encode($list) . json_encode($push) . json_encode($this->db->fetchAllAssociative("SELECT user_id, data FROM loss_journal WHERE type = 'ALERT_SENT'"));
        foreach ([$this->mark, 'Gerant', 'Caisse', '@'] as $never) {
            $this->assertStringNotContainsString($never, $everything, "« $never » n'est jamais dans une alerte");
        }
        $this->assertSame([null], array_values(array_unique(array_map(fn ($v) => $v === null ? null : (int) $v, $this->db->fetchFirstColumn("SELECT user_id FROM loss_journal WHERE type = 'ALERT_SENT'")))));

        // La même minute, la même zone : la seconde pièce rejoint l'alerte (comptée), sans seconde notification
        $this->exit($b, 0);
        $this->assertSame([[$variant, 2]], array_map(fn ($x) => [$x['variantId'], $x['count']], $alerts()['alerts'][0]['articles']));
        $this->assertCount(1, $pushes());
        // Une lecture redite (déjà présente dans la zone) n'alerte pas ; une lecture d'une autre zone non plus
        $this->exit($a, 1);
        $this->assertCount(1, $alerts()['alerts']);
        $this->exit($c, 0, 'floor');
        $this->assertCount(1, $alerts()['alerts']);

        // Une vente de la variante qui attend sa pièce, lue DANS LA COMMANDE (l'analyse n'est pas passée) : la sortie est expliquée
        $this->loginPos(['ROLE_USER_POS']);
        $this->openSession();
        $this->sold($other);
        $this->exit($c, 120);
        $this->assertCount(1, $alerts()['alerts'], 'une écharpe vendue il y a un instant : pas d\'alerte');
        // Une pièce déjà vendue sort sans alerte (une variante sans aucune vente en attente : seule la pièce vendue l'explique)
        $d = $this->tagged($this->article('Bonnet', 5), '04');
        $this->db->executeStatement("UPDATE loss_piece SET status = 'sold' WHERE id = ?", [$this->piece($d)]);
        $this->exit($d, 180);
        $this->assertCount(1, $alerts()['alerts']);

        // Le plafond propre : 3 par jour et par gérant, la 3e dit « d'autres sorties » ; au-delà, l'alerte reste listée, sans notification
        foreach ([240, 300, 360] as $i => $shift) {
            $this->exit($this->tagged($variant, '1' . $i), $shift);
        }
        $this->assertCount(4, $alerts()['alerts']);
        $push = $pushes();
        $this->assertCount(3, $push);
        $this->assertMatchesRegularExpression('/voir la liste|see the list/u', $push[2]['body']);
        $this->assertDoesNotMatchRegularExpression('/voir la liste|see the list/u', $push[1]['body']);
        $this->assertSame(3, $alerts()['pushedToday']);
        // le plafond propre ne touche pas le compte commun du téléphone : une autre notification du jour part encore
        $this->assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM notification_log WHERE user_id = ? AND kind <> 'loss_exit' AND store_day = ? AND channel = 'push'", [$this->adminId, $this->today()]));
        $this->assertSame(0, $this->provider->getEntityManager()->getRepository(\App\Entity\NotificationLog::class)->countToday($this->adminId, $this->today()), 'les alertes de sortie hors du compte commun');

        // Hors des heures (aucune heure confirmée ce jour-là : tout est hors des heures) et la nuit coupée : listée, sans notification
        $this->db->executeStatement("DELETE FROM notification_log WHERE kind = 'loss_exit'");
        $this->db->executeStatement('UPDATE loss_setting SET opening_hours = ?', [json_encode(array_fill_keys(array_map('strval', range(1, 7)), null))]);
        $this->assertFalse($this->json($this->admin('PUT', self::BASE . '/reglages', ['exitAlerts' => ['night' => false]]))['exitAlerts']['night']);
        $this->exit($this->tagged($variant, '20'), 420);
        $night = $alerts()['alerts'][0];
        $this->assertSame([true, 0], [$night['outOfHours'], count($pushes())]);
        $this->json($this->admin('PUT', self::BASE . '/reglages', ['exitAlerts' => ['night' => true]]));
        // deux notifications de sortie déjà parties ce jour : celle de la nuit est la 3e, la dernière du plafond. Elle garde son texte de nuit
        // et dit « d'autres sorties » (relecture 2 : la variante n'est pas remplacée par celle de la dernière)
        foreach ([1, 2] as $k) {
            $this->db->executeStatement("INSERT INTO notification_log (user_id, kind, title, body, action, subject, status, devices, store_day, sent_at, channel) VALUES (?, 'loss_exit', 'avant', 'avant', '{}', '{}', 'sent', 1, ?, NOW(), 'push')",
                [$this->adminId, $this->today()]);
        }
        $this->exit($this->tagged($variant, '21'), 480);
        $push = $pushes();
        $this->assertCount(3, $push, 'la nuit rallumée : la notification part');
        $this->assertMatchesRegularExpression('/hors des heures|outside opening hours/u', $push[2]['title']);
        $this->assertMatchesRegularExpression('/boutique fermée|shop closed/u', $push[2]['body']);
        $this->assertMatchesRegularExpression('/voir la liste|see the list/u', $push[2]['body']);
        $this->db->executeStatement("DELETE FROM notification_log WHERE kind = 'loss_exit' AND title = 'avant'");

        // Sans le droit du module : rien
        $this->db->executeStatement('UPDATE loss_setting SET opening_hours = NULL');
        $count = count($alerts()['alerts']);
        $this->flags(true, false);
        $this->exit($this->tagged($variant, '22'), 540);
        $this->flags(true, true);
        $this->assertCount($count, $alerts()['alerts']);
        // La liste depuis un instant : seulement ce qui a changé
        $this->assertSame([], $this->json($this->admin('GET', self::BASE . '/alertes?since=' . urlencode(gmdate('Y-m-d\TH:i:s\Z', time() + 60))))['alerts']);
        $this->assertSame(['since' => 'INVALID_DATE'], $this->json($this->admin('GET', self::BASE . '/alertes?since=hier-soir'), 422)['fields']);
        $this->closeWith(0);
    }

    public function testTheBoxReadsThePermittedExitsAndTheSignalIsASettingOffByDefault(): void
    {
        $this->install(['serial' => "SON-$this->s", 'kind' => 'signal', 'status' => 'ok']);
        $variant = $this->article('Veste', 50);
        [$sold, $free, $returned] = [$this->tagged($variant, '31'), $this->tagged($variant, '32'), $this->tagged($variant, '33')];
        $units = $this->provider->getEntityManager()->getRepository(LossSaleUnit::class);
        $units->pieceStatus($this->piece($sold), 'sold', gmdate('Y-m-d H:i:s', time() - 86400 * 40)); // vendue il y a 40 jours : elle ne sonne toujours pas

        // La liste complète, puis les deltas
        $full = $this->json($this->channel('GET', '/sorties-permises', null, $this->token));
        $this->assertSame(['serverTime', 'full', 'sold', 'released', 'waiting', 'rentals'], array_keys($full));
        $this->assertTrue($full['full']);
        $this->assertContains($sold, $full['sold']);
        $this->assertNotContains($free, $full['sold']);
        $this->assertSame('no-store, private', $this->client->getResponse()->headers->get('Cache-Control'));
        $units->pieceStatus($this->piece($returned), 'sold', gmdate('Y-m-d H:i:s'));
        // une pièce vendue depuis plus longtemps que le recouvrement n'est pas redite dans un delta
        $this->db->executeStatement("UPDATE loss_piece SET sold_changed_at = NOW() AT TIME ZONE 'UTC' - INTERVAL '2 hours' WHERE id = ?", [$this->piece($sold)]);
        $delta = $this->json($this->channel('GET', '/sorties-permises?since=' . urlencode($full['serverTime']), null, $this->token));
        $this->assertFalse($delta['full']);
        $this->assertContains($returned, $delta['sold'], 'le delta : la pièce vendue depuis');
        $this->assertNotContains($free, $delta['sold']);
        $this->assertNotContains($sold, $delta['sold'], 'vendue il y a deux heures : déjà dans la liste du boîtier');
        // vendue il y a longtemps (hors du recouvrement), puis revenue : le retour se date lui-même
        $this->db->executeStatement("UPDATE loss_piece SET sold_changed_at = NOW() AT TIME ZONE 'UTC' - INTERVAL '3 hours' WHERE id = ?", [$this->piece($returned)]);
        $units->pieceStatus($this->piece($returned), 'returned', gmdate('Y-m-d H:i:s'));
        $back = $this->json($this->channel('GET', '/sorties-permises?since=' . urlencode($delta['serverTime']), null, $this->token));
        $this->assertContains($returned, $back['released'], 'revenue : elle sort de la liste');
        $this->assertNotContains($returned, $back['sold']);
        $this->assertSame(['since' => 'INVALID_DATE'], $this->json($this->channel('GET', '/sorties-permises?since=pas-une-date', null, $this->token), 422)['fields']);
        $this->assertSame(401, $this->channel('GET', '/sorties-permises', null, null)->getStatusCode());
        // Une vente au comptoir pas encore reliée : la variante a une sortie permise, et les puces de ses pièces en service
        $this->loginPos(['ROLE_USER_POS']);
        $this->openSession();
        $this->sold($variant);
        $waiting = array_column($this->json($this->channel('GET', '/sorties-permises', null, $this->token))['waiting'], null, 'variantId')[$variant] ?? null;
        $this->assertNotNull($waiting);
        $this->assertSame(1, $waiting['count']);
        $this->assertContains($free, $waiting['identifiers']);
        $this->assertNotContains($sold, $waiting['identifiers'], 'une pièce vendue est dans « sold », pas dans les puces en service');
        $this->closeWith(0);

        // Le signal : un réglage éteint par défaut ; l'avertisseur en service le rend disponible ; le boîtier lit le réglage
        $settings = $this->json($this->admin('GET', self::BASE . '/reglages'));
        $this->assertSame([false, true], [$settings['exitSignal']['enabled'], $settings['exitSignal']['available']]);
        $this->assertMatchesRegularExpression('/client honnête|honest customer/u', $settings['exitSignal']['limit']);
        $this->assertSame([true], [$settings['exitAlerts']['night']]);
        $this->assertFalse($this->json($this->channel('GET', '/reglages', null, $this->token))['exitSignal']['enabled']);
        foreach ([['exitSignal' => ['enabled' => 'oui']], ['exitSignal' => true], ['exitAlerts' => ['night' => 1]]] as $wrong) {
            $this->assertNotEmpty($this->json($this->admin('PUT', self::BASE . '/reglages', $wrong), 422)['fields']);
        }
        // la santé et l'assistant : l'avertisseur branché attend tant que le signal est éteint
        $signalLine = fn (): array => array_values(array_filter($this->json($this->admin('GET', self::BASE . '/sante?locale=fr'))['devices'], fn (array $d) => $d['kind'] === 'signal'))[0];
        $step = fn (): array => array_column($this->json($this->admin('GET', self::BASE . '/installation?locale=fr'))['optionalSteps'], null, 'code')['exit_signal'];
        $this->assertSame(['pending', 'pending'], [$signalLine()['state'], $step()['state']]);
        $this->assertStringContainsString('éteint', (string) $signalLine()['hint']);
        $this->assertTrue($this->json($this->admin('PUT', self::BASE . '/reglages', ['exitSignal' => ['enabled' => true]]))['exitSignal']['enabled']);
        $this->assertSame(['ok', 'ok'], [$signalLine()['state'], $step()['state']]);
        $this->assertTrue($this->json($this->channel('GET', '/reglages', null, $this->token))['exitSignal']['enabled']);
        // Le portique a sonné : un fait de l'avertisseur, jamais d'un lecteur ; à la sortie ou à la porte arrière seulement
        $this->assertSame(['accepted', null], $this->fact('EXIT_SIGNAL', "SON-$this->s", $free, 'exit'));
        $this->assertSame(['refused', 'DEVICE_KIND_INVALID'], $this->fact('EXIT_SIGNAL', "LECTEUR-$this->s", $free, 'exit'));
        $this->assertSame(['refused', 'ZONE_INVALID'], $this->fact('EXIT_SIGNAL', "SON-$this->s", $free, 'counter'));
        $this->assertSame(['refused', 'DEVICE_KIND_INVALID'], $this->fact('ZONE_SEEN', "SON-$this->s", $free, 'exit'), 'un avertisseur ne lit rien');
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_piece_event WHERE type = 'EXIT_SIGNAL' AND piece_id = ?", [$this->piece($free)]));
    }

    public function testTheLightCheckCountsOnlyPaidWebOrdersAndSkipsReturnsOldReadsRetiredTagsAndReturnedPieces(): void
    {
        $this->install();
        $count = fn (): int => count($this->json($this->admin('GET', self::BASE . '/alertes'))['alerts']);
        $carrier = (int) $this->db->fetchOne('SELECT MIN(id) FROM carrier');
        [$unpaid, $paid, $returnOrder, $old, $retired, $back] = array_map(fn (int $n): int => $this->article("Article $n", 20), range(1, 6));
        $this->webOrder($carrier, $unpaid, false);
        $this->webOrder($carrier, $paid, true);
        $this->returnOrder($returnOrder);
        $before = $count();
        $this->exit($this->tagged($unpaid, '41'), 0);
        $this->assertSame($before + 1, $count(), 'une commande web IMPAYÉE n\'explique pas une sortie');
        $this->exit($this->tagged($paid, '42'), 60);
        $this->assertSame($before + 1, $count(), 'une commande web payée l\'explique');
        $this->exit($this->tagged($returnOrder, '43'), 120);
        $this->assertSame($before + 2, $count(), 'une commande de RETOUR n\'est pas une vente');
        $this->exit($this->tagged($old, '44'), -700);
        $this->assertSame($before + 2, $count(), 'une lecture vieille de plus de 15 minutes n\'alerte plus');
        $tag = $this->tagged($retired, '45');
        $tagId = (int) $this->db->fetchOne('SELECT id FROM loss_tag WHERE identifier = ?', [$tag]);
        $this->json($this->admin('POST', self::BASE . "/puces/$tagId/retirer", ['reason' => 'error', 'confirm' => true]));
        $this->exit($tag, 180);
        $this->assertSame($before + 2, $count(), 'une puce retirée (jetée, remplacée) n\'alerte pas');
        // une pièce revenue (client qui vient la rendre) : un article vendu en ligne, relié à elle, revenu à l'instant
        $piece = $this->tagged($back, '46');
        $order = $this->webOrder($carrier, $back, true);
        $item = (int) $this->db->fetchOne('SELECT id FROM order_items WHERE order_associated_id = ?', [$order]);
        $this->db->executeStatement("INSERT INTO loss_sale_unit (order_id, order_item_id, unit_no, variant_id, cash_register_id, sold_at, audit_sequence, piece_id, matched_by, matched_at, returned_at, return_sequence, returned_by, origin, created_at)"
            . " VALUES (?, ?, 1, ?, NULL, NOW() - INTERVAL '2 days', 0, ?, 'catch_up', NOW() - INTERVAL '2 days', NOW(), 0, 'return', 'web', NOW() - INTERVAL '2 days')", [$order, $item, $back, $this->piece($piece)]);
        $this->db->executeStatement("UPDATE \"order\" SET order_date = NOW() - INTERVAL '30 days' WHERE id = ?", [$order]);
        $this->exit($piece, 240);
        $this->assertSame($before + 2, $count(), 'une pièce revenue à l\'instant n\'alerte pas');
        // les sorties permises : la commande payée, pas l'impayée
        $waiting = array_column($this->json($this->channel('GET', '/sorties-permises', null, $this->token))['waiting'], 'variantId');
        $this->assertContains($paid, $waiting);
        $this->assertNotContains($unpaid, $waiting);
        $this->assertNotContains($returnOrder, $waiting);
        // le contrôle lui-même écarte une lecture d'une autre zone, et une arrivée redite (deux gardes doublées au canal)
        $service = static::getContainer()->get(\App\LossPrevention\Services\LossExitAlertService::class);
        foreach ([['floor', false], ['exit', true]] as [$zone, $repeat]) {
            $loose = $this->tagged($unpaid, $zone === 'floor' ? '47' : '48');
            $event = (int) $this->db->fetchOne("INSERT INTO loss_piece_event (type, occurred_at, piece_id, tag_id, source, event_key, exercise, data, box_id, device_id, received_at)"
                . " SELECT 'ZONE_SEEN', NOW() AT TIME ZONE 'UTC', t.piece_id, t.id, 'box', ?, false, ?, ?, NULL, NOW() FROM loss_tag t WHERE t.identifier = ? RETURNING id",
                ["alerte-direct-$this->s-$zone", json_encode(['zone' => $zone] + ($repeat ? ['repeat' => true] : [])), $this->boxId, $loose]);
            $this->assertNull($service->check($event), "$zone" . ($repeat ? ' redite' : ''));
        }
    }

    public function testTheSimulatedBoxGivesAnExerciseAlertToTheManagerWhoPlaysIt(): void
    {
        $box = (int) $this->json($this->admin('POST', self::BASE . '/exercices/boitiers'), 201)['box']['id'];
        $this->json($this->admin('POST', self::BASE . '/exercices/puces/associer', ['variantId' => $this->article('Robe', 10)]));
        // un boîtier simulé créé avant l'avertisseur (relecture 2) : il le reçoit à la première scène jouée, une seule fois
        $this->db->transactional(function () use ($box): void {
            $this->db->executeStatement("SET LOCAL loss.purge = 'exercise'"); // la porte de l'effacement des exercices, le temps de retirer l'appareil
            $this->db->executeStatement("DELETE FROM loss_box_event WHERE box_id = ? AND device_id = (SELECT id FROM loss_device WHERE box_id = ? AND serial = 'SIM-AVERTISSEUR')", [$box, $box]);
            $this->db->executeStatement("DELETE FROM loss_device WHERE box_id = ? AND serial = 'SIM-AVERTISSEUR'", [$box]);
        });
        $this->json($this->admin('POST', self::BASE . "/exercices/boitiers/$box/scenarios", ['scenario' => 'heartbeat']));
        $this->json($this->admin('POST', self::BASE . "/exercices/boitiers/$box/scenarios", ['scenario' => 'zone_exit']));
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_device WHERE box_id = ? AND serial = 'SIM-AVERTISSEUR' AND kind = 'signal'", [$box]));
        $exercise = $this->json($this->admin('GET', self::BASE . '/exercices/alertes'));
        $this->assertSame([true, 1], [$exercise['exercise'], count($exercise['alerts'])]);
        // l'exercice d'un autre gérant, la même minute : il ne paraît pas dans la liste de celui-ci
        $this->db->executeStatement("INSERT INTO loss_alert (exercise, zone, minute, store_day, out_of_hours, articles, pieces, pushed, requested_by, created_at, updated_at)"
            . " SELECT true, zone, minute, store_day, out_of_hours, articles, pieces, 0, ?, created_at, updated_at FROM loss_alert WHERE exercise AND requested_by = ?", [$this->adminId + 100000, $this->adminId]);
        $this->assertCount(1, $this->json($this->admin('GET', self::BASE . '/exercices/alertes'))['alerts'], 'les exercices des autres gérants ne se mêlent pas');
        $real = $this->json($this->admin('GET', self::BASE . '/alertes'));
        $this->assertSame([[], 0], [$real['alerts'], $real['pushedToday']], 'l\'exercice ne se mêle pas aux vraies alertes, ni à leur plafond');
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM notification_log WHERE kind = 'loss_exit' AND user_id = ?", [$this->adminId]), 'au seul gérant qui l\'a lancé');
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM notification_log WHERE kind = 'loss_exit'"), 'à personne d\'autre');
        // l'avertisseur simulé a sonné : un fait d'exercice, de son appareil
        $this->assertSame(['SIM-AVERTISSEUR'], $this->db->fetchFirstColumn("SELECT d.serial FROM loss_piece_event e JOIN loss_device d ON d.id = e.device_id WHERE e.type = 'EXIT_SIGNAL' AND e.exercise AND e.box_id = ?", [$box]));
        $this->assertSame('ok', array_values(array_filter($this->json($this->admin('GET', self::BASE . '/exercices/sante?locale=fr'))['devices'], fn (array $d) => $d['kind'] === 'signal'))[0]['state']);
    }

    /** Relecture 2 : une base passée par la première version du lot garde un index sans COALESCE ; la migration le remplace, et son script la suit */
    public function testTheMigrationReplacesAnIndexOfTheOldShapeAndItsScriptCarriesTheSameStatements(): void
    {
        require_once dirname(__DIR__, 3) . '/migrations/Version20270129100000.php';
        $shape = fn (): string => (string) $this->db->fetchOne("SELECT indexdef FROM pg_indexes WHERE schemaname = current_schema() AND indexname = 'uniq_loss_alert_minute'");
        $this->db->beginTransaction();
        try {
            $this->db->executeStatement('DELETE FROM loss_alert'); // des alertes d'exercice de la même minute (gérants différents) empêcheraient l'ancienne forme
            $this->db->executeStatement('DROP INDEX uniq_loss_alert_minute');
            $this->db->executeStatement('CREATE UNIQUE INDEX uniq_loss_alert_minute ON loss_alert (exercise, zone, minute)');
            $this->assertStringNotContainsStringIgnoringCase('coalesce', $shape());
            foreach ([1, 2] as $ignored) { // rejouée : idempotente
                foreach (\DoctrineMigrations\Version20270129100000::STATEMENTS as $sql) {
                    $this->db->executeStatement($sql);
                }
                $this->assertStringContainsStringIgnoringCase('coalesce(requested_by, 0)', $shape());
            }
        } finally {
            $this->db->rollBack();
        }
        $this->assertStringContainsStringIgnoringCase('coalesce(requested_by, 0)', $shape());
        $script = (string) @file_get_contents(dirname(__DIR__, 3) . '/scripts/migrate_all_v2_pertes_l5d.sh');
        if ($script !== '') {
            foreach (\DoctrineMigrations\Version20270129100000::STATEMENTS as $sql) {
                $this->assertStringContainsString($sql . ';', $script);
            }
        }
        $this->assertSame(12, count(\DoctrineMigrations\Version20270129100000::STATEMENTS));
    }

    // ----- aides

    /** Un boîtier inscrit, un lecteur à la sortie (et d'autres appareils au besoin) */
    private function install(array ...$more): void
    {
        $created = $this->json($this->admin('POST', self::BOXES, ['name' => "Alerte $this->s"]), 201);
        $this->boxId = (int) $created['box']['id'];
        $enrolment = $this->json($this->channel('POST', '/inscription', ['key' => $created['key'], 'machine' => self::MACHINE, 'version' => '1.0.0', 'arch' => 'amd64'], null));
        $this->token = $enrolment['token'];
        $settings = $this->json($this->channel('POST', '/signe-de-vie', ['now' => gmdate('Y-m-d\TH:i:s\Z'), 'machine' => self::MACHINE, 'lastServerTime' => $enrolment['serverTime'],
            'devices' => [['serial' => "LECTEUR-$this->s", 'kind' => 'reader', 'status' => 'ok'], ['serial' => "RAYON-$this->s", 'kind' => 'reader', 'status' => 'ok'], ...$more]], $this->token))['settings'];
        $ids = array_column($settings['devices'], 'id', 'serial');
        $this->json($this->admin('PUT', self::BOXES . "/$this->boxId/appareils/" . $ids["LECTEUR-$this->s"], ['zone' => 'exit']));
        $this->json($this->admin('PUT', self::BOXES . "/$this->boxId/appareils/" . $ids["RAYON-$this->s"], ['zone' => 'floor']));
    }

    /** @return array{0: string, 1: ?string} une arrivée de la puce devant l'antenne de la sortie (ou du rayon), décalée de `$shift` secondes */
    private function exit(string $identifier, int $shift, string $zone = 'exit'): array
    {
        return $this->fact('ZONE_SEEN', $zone === 'floor' ? "RAYON-$this->s" : "LECTEUR-$this->s", $identifier, $zone, $shift);
    }

    /** @return array{0: string, 1: ?string} */
    private function fact(string $type, string $device, string $identifier, string $zone, int $shift = 0): array
    {
        $fact = ['key' => 'alerte-' . $this->s . '-' . str_pad((string) ++$this->n, 4, '0', STR_PAD_LEFT), 'type' => $type, 'at' => gmdate('Y-m-d\TH:i:s\Z', time() - 600 + $shift),
            'device' => $device, 'zone' => $zone, 'data' => ['identifier' => $identifier]];
        $result = $this->json($this->channel('POST', '/faits', ['facts' => [$fact]], $this->token))['results'][0];

        return [$result['status'], $result['code']];
    }

    /** Une vente au comptoir d'un article, payée en espèces ; rend la commande */
    private function sold(int $variant): int
    {
        $body = ['items' => [['productVariantId' => $variant, 'quantity' => 1]], 'cashRegisterId' => $this->register];
        $quote = $this->json($this->pos('POST', '/api/pos/quote', $body));

        return (int) $this->json($this->pos('POST', '/api/order/create-multi-payment', $body + ['paymentMethods' => [['type' => 2, 'amount' => $quote['cashTotal'] / 100]]]), 201)['orderId'];
    }

    /** Une commande web (retrait ou expédition), payée ou non, d'un article ; rend la commande */
    private function webOrder(int $carrier, int $variant, bool $paid): int
    {
        $order = (int) $this->db->fetchOne(
            "INSERT INTO \"order\" (id, reference, order_date, order_type_id, status_id, order_source_id, user_id_id, total_amount, gift_card_applied, carrier_id, shipping_cost) VALUES (nextval('order_id_seq'), ?, ?, 1, 3, 1, ?, 3000, 0, ?, 0) RETURNING id",
            ["L5D-$this->s", gmdate('Y-m-d H:i:s', time() - 3600), $this->adminId, $carrier]);
        $this->db->executeStatement("INSERT INTO order_items (id, order_associated_id, product_variant_id, quantity, unit_price, total_price, discount_amount, taxable) VALUES (nextval('order_items_id_seq'), ?, ?, 1, 3000, 3000, 0, true)", [$order, $variant]);
        $this->db->executeStatement("INSERT INTO payments (id, order_payment_id, statut_payment_id, payment_type_id, amount, payment_date) VALUES (nextval('payments_id_seq'), ?, ?, 2, 3000, NOW())", [$order, $paid ? 2 : 1]);

        return $order;
    }

    /** Une commande de RETOUR au comptoir d'un article (type 2) : jamais une vente */
    private function returnOrder(int $variant): void
    {
        $order = (int) $this->db->fetchOne(
            "INSERT INTO \"order\" (id, reference, order_date, order_type_id, status_id, order_source_id, user_id_id, total_amount, gift_card_applied) VALUES (nextval('order_id_seq'), ?, ?, 2, 3, 2, ?, 3000, 0) RETURNING id",
            ["L5D-R-$this->s", gmdate('Y-m-d H:i:s', time() - 300), $this->adminId]);
        $this->db->executeStatement("INSERT INTO order_items (id, order_associated_id, product_variant_id, quantity, unit_price, total_price, discount_amount, taxable) VALUES (nextval('order_items_id_seq'), ?, ?, 1, 3000, 3000, 0, true)", [$order, $variant]);
    }

    private function piece(string $identifier): int
    {
        return (int) $this->db->fetchOne('SELECT piece_id FROM loss_tag WHERE identifier = ?', [$identifier]);
    }

    /** Une vraie pièce et sa puce NFC, posées il y a une heure */
    private function tagged(int $variant, string $suffix): string
    {
        $nfc = '04' . $this->s . 'DD00' . $suffix;
        $at = gmdate('Y-m-d H:i:s', time() - 3600);
        $piece = (int) $this->db->fetchOne('INSERT INTO loss_piece (number, variant_id, product_id, status, exercise, created_at, updated_at) VALUES (?, ?,'
            . " (SELECT product_id FROM product_variant WHERE id = ?), 'created', false, ?, ?) RETURNING id", [900000000 + random_int(1, 99999999), $variant, $variant, $at, $at]);
        $this->db->executeStatement("INSERT INTO loss_tag (identifier, raw, technology, status, piece_id, exercise, associated_at, created_at, updated_at) VALUES (?, ?, 'nfc', 'associated', ?, false, ?, ?, ?)", [$nfc, $nfc, $piece, $at, $at, $at]);

        return $nfc;
    }

    private function channel(string $method, string $path, ?array $body, ?string $token): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . '/api/pertes/boitier' . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_LOSS_BOX' => $token,
            'HTTP_X_LOSS_BOX_MACHINE' => $token !== null ? ($this->machines[$token] ?? self::MACHINE) : null, // lot L5-e : la preuve de la machine
        ]) + ['REMOTE_ADDR' => $this->ip], $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    /** Un article d'une variante, avec ce stock ; rend la variante */
    private function article(string $name, int $stock): int
    {
        $slug = 'l5d-' . bin2hex(random_bytes(4));
        $this->db->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, is_web, is_pos) VALUES (nextval('product_id_seq'), :n, 3000, false, 0, NOW(), :s, 'standard', false, true)",
            ['n' => "$name L5D-$this->s", 's' => $slug]);
        $product = (int) $this->db->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]);

        return (int) $this->db->fetchOne("INSERT INTO product_variant (id, product_id, stock_quantity) VALUES (nextval('product_variant_id_seq'), ?, ?) RETURNING id", [$product, $stock]);
    }
}
