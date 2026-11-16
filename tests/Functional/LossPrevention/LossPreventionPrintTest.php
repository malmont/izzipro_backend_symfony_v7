<?php

namespace App\Tests\Functional\LossPrevention;

use App\LossPrevention\Entity\LossBox;
use App\LossPrevention\Entity\LossPiece;
use App\LossPrevention\Services\LossTagEpc;
use App\LossPrevention\Services\LossTagVault;
use App\Services\Platform\PlatformModeService;
use Doctrine\DBAL\ArrayParameterType;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot L3-c (16/11/2026, `docs/prevention-pertes.md` § 16.20) : l'imprimante à puce. Le travail d'impression que le gérant crée, ce
 * que le boîtier vient chercher, le résultat de chaque étiquette (puce écrite et relue : posée sur la pièce ; échec : la pièce
 * reste sans puce), le mot de passe d'accès des puces (rangé chiffré, remis au seul boîtier, écrit nulle part), la clé de
 * chiffrement absente, l'imprimante simulée, et ce que la base garde elle-même.
 */
class LossPreventionPrintTest extends LossPreventionTestCase
{
    use LossTagKeysTrait;

    private const BOXES = self::BASE . '/boitiers';
    private const PRINTS = self::BASE . '/impressions';
    private const EX = self::BASE . '/exercices';
    private const MACHINE = 'machine-impression-0123456789abcdef';

    private string $s;
    private string $ip;
    private int $n = 0;
    /** @var array<string, string> la machine de chaque jeton */
    private array $machines = [];
    /** @var array<string, string> l'heure du serveur lue dans la dernière réponse reçue par chaque boîtier d'essai */
    private array $answered = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = strtoupper(bin2hex(random_bytes(3)));
        $this->ip = '10.' . random_int(1, 250) . '.' . random_int(1, 250) . '.' . random_int(1, 250);
        $this->flags(true, true);
        $this->tagKeys(self::tagKey(1));
    }

    protected function tearDown(): void
    {
        try {
            while ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $this->tagKeys(null);
            // les travaux ouverts se ferment (une ligne en attente s'annule : la base le permet), boîtiers et appareils rendus aux tests suivants
            $this->db->executeStatement("UPDATE loss_print_job_line SET status = 'cancelled', done_at = NOW() WHERE status = 'waiting'");
            $this->db->executeStatement("UPDATE loss_print_job SET status = 'cancelled', cancelled_at = NOW() WHERE status IN ('waiting', 'running')");
            $this->db->executeStatement("UPDATE loss_box SET status = 'revoked', revoked_at = NOW() WHERE NOT exercise AND status <> 'revoked'");
            $this->db->executeStatement("UPDATE loss_device SET status = 'retired', cash_register_id = NULL WHERE NOT exercise AND status <> 'retired'");
            $this->db->beginTransaction();
            $this->provider->getEntityManager()->getRepository(LossPiece::class)->deleteExercise();
            $this->provider->getEntityManager()->getRepository(LossBox::class)->deleteExercise();
            $this->db->commit();
            $ids = $this->db->fetchFirstColumn('SELECT id FROM product WHERE name LIKE ?', ['% L3C-' . $this->s]);
            if ($ids !== []) {
                $this->db->executeStatement('DELETE FROM product_variant WHERE product_id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
                $this->db->executeStatement('DELETE FROM product WHERE id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testAPrintJobIsServedToItsBoxWrittenReadBackAndFollowed(): void
    {
        $variant = $this->article('Manteau');
        [$p1, $p2, $p3, $p4] = $this->pieces($variant, 4);
        [$boxId, $token, $devices] = $this->enrolled('Arrière-boutique', [['serial' => "IMPR-$this->s", 'kind' => 'printer', 'status' => 'ok'], ['serial' => "TAPIS-$this->s", 'kind' => 'counter_mat', 'status' => 'ok'],
            ['serial' => "IMPR-B-$this->s", 'kind' => 'printer', 'status' => 'ok']]);
        [$printer, $mat] = [$devices["IMPR-$this->s"], $devices["TAPIS-$this->s"]];
        [, $otherToken] = $this->enrolled('Autre boîtier', [['serial' => "IMPR2-$this->s", 'kind' => 'printer', 'status' => 'ok']], 'machine-impression-autre-0123456789');
        $create = fn (array $body, int $status = 201): array => $this->json($this->admin('POST', self::PRINTS, $body), $status);

        // L'écran : l'imprimante est proposée, tout est prêt ; le tableau de santé attend sa première puce
        $list = $this->json($this->admin('GET', self::PRINTS));
        $this->assertSame(['items', 'total', 'page', 'limit', 'printers', 'ready', 'limits', 'exercise'], array_keys($list));
        $this->assertSame([['ok' => true, 'reason' => null, 'message' => null], ['lines' => 200, 'openJobs' => 20], false], [$list['ready'], $list['limits'], $list['exercise']]);
        $mine = array_column($list['printers'], null, 'deviceId')[$printer];
        $this->assertSame(['deviceId' => $printer, 'serial' => "IMPR-$this->s", 'model' => null, 'status' => 'ok', 'boxId' => $boxId, 'boxName' => "Arrière-boutique $this->s", 'boxEnrolled' => true], $mine);
        $this->assertArrayNotHasKey($mat, array_column($list['printers'], null, 'deviceId'), 'un tapis n\'est pas une imprimante');
        $line = fn (): array => array_column($this->json($this->admin('GET', self::BASE . '/sante'))['devices'], null, 'code')["printer-$printer"];
        $this->assertSame(['printer', 'Imprimante à puce', 'pending', 'L\'imprimante n\'a encore écrit aucune puce. Lancez un travail d\'impression sur une pièce.'],
            [$line()['kind'], $line()['label'], $line()['state'], $line()['hint']]);
        $step = fn (): array => array_column($this->json($this->admin('GET', self::BASE . '/installation'))['optionalSteps'], null, 'code')['tag_printer'];
        $this->assertSame(['Brancher l\'imprimante à puce', 'todo', 'pending'], [$step()['title'], $step()['status'], $step()['state']]);

        // Ce qui est refusé à la création
        $this->assertSame(['deviceId' => 'REQUIRED'], $create([], 422)['fields']);
        $this->assertSame(['deviceId' => 'MUST_BE_POSITIVE', 'withPrice' => 'MUST_BE_BOOLEAN', 'confirmTagged' => 'MUST_BE_BOOLEAN'],
            $create(['deviceId' => 0, 'pieceIds' => [$p1], 'withPrice' => 'oui', 'confirmTagged' => 1], 422)['fields']);
        $this->assertSame(['pieceIds' => 'REQUIRED'], $create(['deviceId' => $printer], 422)['fields']);
        $this->assertSame(['pieceIds' => 'MUST_BE_DISTINCT_POSITIVE'], $create(['deviceId' => $printer, 'pieceIds' => [$p1, $p1]], 422)['fields']);
        $this->assertSame('PIECES_NOT_FOUND', $create(['deviceId' => $printer, 'pieceIds' => [999999999]], 422)['code']);
        $this->assertSame('PRINTER_NOT_FOUND', $create(['deviceId' => $mat, 'pieceIds' => [$p1]], 404)['code'], 'un tapis n\'imprime pas');
        $this->assertSame('PRINTER_NOT_FOUND', $create(['deviceId' => 999999999, 'pieceIds' => [$p1]], 404)['code']);
        $this->assertSame(['LABELS_LIMIT_EXCEEDED', 200], [($many = $create(['deviceId' => $printer, 'pieceIds' => range(1, 201)], 422))['code'], $many['max']]);
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_print_job_line WHERE piece_id IN (?, ?, ?, ?)', [$p1, $p2, $p3, $p4]), 'un refus n\'écrit rien');

        // Le travail : une ligne par pièce, dans l'ordre demandé, avec l'identifiant de 96 bits à écrire
        $job = $create(['deviceId' => $printer, 'pieceIds' => [$p3, $p1, $p2], 'withPrice' => true]);
        $id = $job['id'];
        $this->assertSame(['waiting', 'En attente', true, 3, 0, 0, 3, 0, false], [$job['status'], $job['statusLabel'], $job['withPrice'], $job['lines'], $job['written'], $job['failed'], $job['waiting'], $job['cancelled'], $job['exercise']]);
        $this->assertSame(['deviceId' => $printer, 'serial' => "IMPR-$this->s", 'model' => null, 'boxId' => $boxId, 'boxName' => "Arrière-boutique $this->s"], $job['printer']);
        $this->assertSame([[1, $p3], [2, $p1], [3, $p2]], array_map(fn (array $l): array => [$l['position'], $l['piece']['id']], $job['lineItems']));
        $this->assertSame(['id', 'position', 'piece', 'identifier', 'status', 'statusLabel', 'reason', 'reasonLabel', 'tagId', 'doneAt'], array_keys($job['lineItems'][0]));
        $this->assertSame(["Manteau L3C-$this->s", 'waiting', 'En attente', null, null, null], [$job['lineItems'][0]['piece']['name'], $job['lineItems'][0]['status'], $job['lineItems'][0]['statusLabel'],
            $job['lineItems'][0]['reason'], $job['lineItems'][0]['tagId'], $job['lineItems'][0]['doneAt']]);
        $lines = array_column($job['lineItems'], null, 'position');
        $identifiers = array_column($job['lineItems'], 'identifier');
        $this->assertCount(3, array_unique($identifiers));
        foreach ($job['lineItems'] as $l) {
            $this->assertMatchesRegularExpression('/\AF1A7[0-9A-F]{20}\z/', $l['identifier']);
            $this->assertTrue(LossTagEpc::isOurs($l['identifier'], MV_TEST_TENANT_CODE));
            $this->assertSame(LossTagEpc::make(MV_TEST_TENANT_CODE, $l['piece']['number'], 0), $l['identifier'], 'première étiquette de la pièce : exemplaire 0');
        }
        $this->assertSame($job, $this->json($this->admin('GET', self::PRINTS . "/$id")));
        $this->assertSame($id, $this->json($this->admin('GET', self::PRINTS))['items'][0]['id'], 'le plus récent d\'abord');
        $this->assertArrayNotHasKey('lineItems', $this->json($this->admin('GET', self::PRINTS))['items'][0]);
        $this->assertSame(['PIECES_ALREADY_QUEUED', [$p1, $p2]], [($q = $create(['deviceId' => $printer, 'pieceIds' => [$p4, $p2, $p1]], 409))['code'], $q['pieceIds']]);
        // Vingt travaux ouverts au plus sur un site : le vingt-et-unième attend qu'un autre finisse
        $open = (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_print_job WHERE NOT exercise AND status IN ('waiting', 'running')");
        $this->db->executeStatement("INSERT INTO loss_print_job (box_id, device_id, secret_rank, created_at) SELECT ?, ?, 1, NOW() FROM generate_series(1, ?)", [$boxId, $printer, 20 - $open]);
        $this->assertSame(['PRINT_JOBS_LIMIT', 20], [($full = $create(['deviceId' => $printer, 'pieceIds' => [$p4]], 409))['code'], $full['limit']]);
        $this->db->executeStatement("UPDATE loss_print_job SET status = 'cancelled', cancelled_at = NOW() WHERE id <> ? AND NOT exercise AND status = 'waiting'", [$id]);

        // Le boîtier vient chercher ses travaux : les textes, le code-barres, l'identifiant, le mot de passe d'accès. Lui seul
        $fetched = $this->box('GET', '/travaux', null, $token);
        $this->assertSame(200, $fetched->getStatusCode());
        $this->assertStringContainsString('no-store', (string) $fetched->headers->get('Cache-Control'));
        $jobs = $this->json($fetched);
        $this->assertSame(['serverTime', 'jobs', 'more'], array_keys($jobs));
        $this->assertSame([1, false], [count($jobs['jobs']), $jobs['more']]);
        $served = $jobs['jobs'][0];
        $this->assertSame(['id', 'printer', 'withPrice', 'access', 'lines'], array_keys($served));
        $this->assertSame([$id, "IMPR-$this->s", true, 1], [$served['id'], $served['printer'], $served['withPrice'], $served['access']['rank']]);
        $this->assertMatchesRegularExpression('/\A[0-9A-F]{8}\z/', $served['access']['password']);
        $this->assertSame(['id', 'position', 'identifier', 'piece', 'title', 'subtitle', 'barcode', 'symbology', 'price', 'note'], array_keys($served['lines'][0]));
        $this->assertSame([$lines[1]['id'], 1, $lines[1]['identifier'], $lines[1]['piece']['number'], "Manteau L3C-$this->s", "L3C$this->s", '$30.00'], // le site d'essai est en Ontario : étiquette en anglais
            [$served['lines'][0]['id'], $served['lines'][0]['position'], $served['lines'][0]['identifier'], $served['lines'][0]['piece'], $served['lines'][0]['title'], $served['lines'][0]['barcode'],
                str_replace("\u{a0}", ' ', (string) $served['lines'][0]['price'])]);
        $this->assertSame($identifiers, array_column($served['lines'], 'identifier'));
        $this->assertSame([], $this->json($this->box('GET', '/travaux', null, $otherToken))['jobs'], 'un autre boîtier ne reçoit pas ce travail');
        $this->assertSame(401, $this->box('GET', '/travaux', null, null)->getStatusCode());
        $this->assertSame(['waiting', 3], [($again = $this->json($this->admin('GET', self::PRINTS . "/$id")))['status'], $again['waiting']], 'venir chercher un travail n\'écrit rien');

        // Les résultats. Une puce écrite et RELUE conforme se pose sur la pièce ; le même fait rejoué ne pose rien de plus
        $written = fn (array $l, ?string $identifier = null, ?string $serial = null, ?string $key = null, ?string $from = null): array => $this->fact($from ?? $token, 'TAG_WRITTEN', $serial ?? "IMPR-$this->s",
            ['line' => $l['id'], 'identifier' => $identifier ?? $l['identifier']], $key);
        $failed = fn (array $l, string $reason, ?string $key = null): array => $this->fact($token, 'TAG_WRITE_FAILED', "IMPR-$this->s", ['line' => $l['id'], 'reason' => $reason], $key);
        $tagsOf = fn (int $piece): array => $this->json($this->admin('GET', self::BASE . "/pieces/$piece"))['tags'];
        $this->assertSame(['refused', 'IDENTIFIER_MISMATCH'], $written($lines[1], LossTagEpc::make(MV_TEST_TENANT_CODE, 4000000000, 9)), 'l\'imprimante a relu autre chose : rien n\'est associé');
        $this->assertSame(['refused', 'TAG_INVALID'], $written($lines[1], 'pas une puce'));
        $this->assertSame(['refused', 'DEVICE_KIND_INVALID'], $written($lines[1], null, "TAPIS-$this->s"), 'seule une imprimante écrit une puce');
        $this->assertSame(['refused', 'DEVICE_UNKNOWN'], $written($lines[1], null, 'INCONNU'));
        $this->assertSame(['refused', 'PRINT_LINE_UNKNOWN'], $written(['id' => 999999999, 'identifier' => $lines[1]['identifier']]));
        $this->assertSame(['refused', 'PRINT_LINE_UNKNOWN'], $written($lines[1], null, "IMPR2-$this->s", null, $otherToken), 'la ligne d\'un autre boîtier lui est inconnue');
        $this->assertSame(['refused', 'PRINT_LINE_UNKNOWN'], $written($lines[1], null, "IMPR-B-$this->s"), 'une autre imprimante du même boîtier ne clôt pas cette ligne');
        $this->assertSame(['refused', 'FACT_INVALID'], $this->fact($token, 'TAG_WRITTEN', "IMPR-$this->s", ['line' => 'un', 'identifier' => $lines[1]['identifier']]));
        $this->assertSame(['refused', 'REASON_INVALID'], $failed($lines[1], 'mauvaise humeur'));
        // un lot qui mêle un résultat d'imprimante et une lecture : chaque fait a sa réponse, le lot n'échoue pas
        $mixed = $this->json($this->box('POST', '/faits', ['facts' => [
            ['key' => "mixte-$this->s-0001", 'type' => 'TAG_WRITE_FAILED', 'at' => gmdate('Y-m-d\TH:i:s\Z'), 'device' => "IMPR-$this->s", 'data' => ['line' => 999999999, 'reason' => 'write_refused']],
            ['key' => "mixte-$this->s-0002", 'type' => 'SPOT_CHECK', 'at' => gmdate('Y-m-d\TH:i:s\Z'), 'device' => "IMPR-$this->s", 'data' => ['identifier' => 'E2' . str_repeat('0', 22)]],
        ]], $token));
        $this->assertSame([2, 0, 0, 2, [['refused', 'PRINT_LINE_UNKNOWN'], ['refused', 'DEVICE_KIND_INVALID']]],
            [$mixed['received'], $mixed['accepted'], $mixed['duplicates'], $mixed['refused'], array_map(fn (array $r): array => [$r['status'], $r['code']], $mixed['results'])]);
        $this->assertSame([[], 'waiting'], [$tagsOf($p3), $this->json($this->admin('GET', self::PRINTS . "/$id"))['status']], 'aucun refus n\'a rien posé ni clos');
        $this->assertSame(['accepted', null], $written($lines[1], strtolower($lines[1]['identifier']), null, "ecrit-$this->s-0001"));
        $this->assertSame(['duplicate', null], $written($lines[1], null, null, "ecrit-$this->s-0001"), 'le même fait rejoué');
        $this->assertSame(['duplicate', null], $written($lines[1]), 'le même résultat sous une autre clé');
        $this->assertSame(['refused', 'PRINT_LINE_CLOSED'], $failed($lines[1], 'write_refused'), 'un résultat contraire après coup');
        $tags = $tagsOf($p3);
        $this->assertSame([1, $lines[1]['identifier'], 'uhf', 'associated'], [count($tags), $tags[0]['identifier'], $tags[0]['technology'], $tags[0]['status']]);
        $event = $this->db->fetchAssociative("SELECT type, source, box_id, device_id, user_id, data FROM loss_piece_event WHERE piece_id = ? AND type = 'TAG_ASSOCIATED'", [$p3]);
        $this->assertSame(['TAG_ASSOCIATED', 'box', $boxId, $printer, null, ['technology' => 'uhf', 'printLineId' => $lines[1]['id']]],
            [$event['type'], $event['source'], (int) $event['box_id'], (int) $event['device_id'], $event['user_id'], json_decode((string) $event['data'], true)]);
        // Une écriture en échec : la pièce reste sans puce, la ligne dit pourquoi
        $this->assertSame(['accepted', null], $failed($lines[2], 'write_refused', "echec-$this->s-0001"));
        $this->assertSame(['duplicate', null], $failed($lines[2], 'write_refused'));
        $this->assertSame(['refused', 'PRINT_LINE_CLOSED'], $failed($lines[2], 'out_of_labels'), 'un autre motif après coup');
        $this->assertSame(['refused', 'PRINT_LINE_CLOSED'], $written($lines[2]));
        $this->assertSame([], $tagsOf($p1));
        $sheet = $this->json($this->admin('GET', self::PRINTS . "/$id"));
        $this->assertSame(['running', 'En cours', 1, 1, 1, 0, null], [$sheet['status'], $sheet['statusLabel'], $sheet['written'], $sheet['failed'], $sheet['waiting'], $sheet['cancelled'], $sheet['finishedAt']]);
        $this->assertSame([['written', 'Écrite', null, null, $tags[0]['id']], ['failed', 'En échec', 'write_refused', 'Écriture refusée par la puce', null], ['waiting', 'En attente', null, null, null]],
            array_map(fn (array $l): array => [$l['status'], $l['statusLabel'], $l['reason'], $l['reasonLabel'], $l['tagId']], $sheet['lineItems']));
        $this->assertNotNull($sheet['lineItems'][0]['doneAt']);
        $this->assertSame([[$lines[3]['id']]], array_map(fn (array $j): array => array_column($j['lines'], 'id'), $this->json($this->box('GET', '/travaux', null, $token))['jobs']), 'seules les lignes qui attendent sont servies');
        $this->assertSame(['ok', null], [$line()['state'], $line()['hint']], 'l\'imprimante a écrit et relu une puce');
        $this->assertSame(['done', 'ok'], [$step()['status'], $step()['state']]);

        // Annuler : la ligne qui attend ne sera plus imprimée ; ce qui est écrit le reste ; le même geste deux fois répond pareil
        $cancelled = $this->json($this->admin('POST', self::PRINTS . "/$id/annuler"));
        $this->assertSame([1, 'cancelled', 'Annulé', 1, 1, 0, 1], [$cancelled['cancelledLines'], $cancelled['status'], $cancelled['statusLabel'], $cancelled['written'], $cancelled['failed'], $cancelled['waiting'], $cancelled['cancelled']]);
        $this->assertNotNull($cancelled['cancelledAt']);
        $this->assertSame([0, 'cancelled'], [($twice = $this->json($this->admin('POST', self::PRINTS . "/$id/annuler")))['cancelledLines'], $twice['status']]);
        $this->assertSame('PRINT_JOB_NOT_FOUND', $this->json($this->admin('POST', self::PRINTS . '/999999999/annuler'), 404)['code']);
        $this->assertSame('PRINT_JOB_NOT_FOUND', $this->json($this->admin('GET', self::PRINTS . '/999999999'), 404)['code']);
        $this->assertSame(['refused', 'PRINT_LINE_CLOSED'], $written($lines[3]), 'une étiquette écrite après l\'annulation n\'est pas associée');
        $this->assertSame([[], []], [$tagsOf($p2), $this->json($this->box('GET', '/travaux', null, $token))['jobs']]);

        // Réimprimer : la pièce restée sans puce reçoit un AUTRE identifiant ; celle qui a sa puce demande une confirmation
        $this->assertSame(['PIECES_ALREADY_TAGGED', [$p3]], [($t = $create(['deviceId' => $printer, 'pieceIds' => [$p1, $p3]], 409))['code'], $t['pieceIds']]);
        $second = $create(['deviceId' => $printer, 'pieceIds' => [$p1, $p3], 'confirmTagged' => true]);
        $this->assertSame([false, 2], [$second['withPrice'], $second['lines']]);
        $this->assertSame([LossTagEpc::make(MV_TEST_TENANT_CODE, $second['lineItems'][0]['piece']['number'], 1), LossTagEpc::make(MV_TEST_TENANT_CODE, $second['lineItems'][1]['piece']['number'], 1)],
            array_column($second['lineItems'], 'identifier'), 'deuxième étiquette de chaque pièce : exemplaire 1');
        $this->assertNull($this->json($this->box('GET', '/travaux', null, $token))['jobs'][0]['lines'][0]['price'], 'sans le prix');
        $this->assertSame(['accepted', null], $written($second['lineItems'][0]));
        $this->assertSame(['running', null], [($mid = $this->json($this->admin('GET', self::PRINTS . '/' . $second['id'])))['status'], $mid['finishedAt']]);
        $this->assertSame(['accepted', null], $written($second['lineItems'][1]));
        $done = $this->json($this->admin('GET', self::PRINTS . '/' . $second['id']));
        $this->assertSame(['done', 'Terminé', 2, 0], [$done['status'], $done['statusLabel'], $done['written'], $done['waiting']]);
        $this->assertNotNull($done['finishedAt']);
        $this->assertSame([1, 2], [count($tagsOf($p1)), count($tagsOf($p3))], 'la pièce confirmée porte ses deux puces');
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_piece_event WHERE piece_id = ? AND type = 'PAIR_SET'", [$p3]));
        $this->assertSame(0, (int) $this->json($this->admin('POST', self::PRINTS . '/' . $second['id'] . '/annuler'))['cancelledLines'], 'un travail fini ne s\'annule plus');
        $this->assertSame('done', $this->json($this->admin('GET', self::PRINTS . '/' . $second['id']))['status']);

        // Deux résultats dans un même lot : chacun clôt sa ligne, le travail finit (leurs pièces et leur travail sont tenus d'avance)
        $p5 = $this->pieces($variant, 1)[0];
        $third = $create(['deviceId' => $printer, 'pieceIds' => [$p5, $p4]]);
        $batch = $this->json($this->box('POST', '/faits', ['facts' => array_map(fn (array $l): array => ['key' => "lot-$this->s-" . $l['id'], 'type' => 'TAG_WRITTEN', 'at' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => "IMPR-$this->s", 'data' => ['line' => $l['id'], 'identifier' => $l['identifier']]], $third['lineItems'])], $token));
        $this->assertSame([2, 2, ['accepted', 'accepted']], [$batch['received'], $batch['accepted'], array_column($batch['results'], 'status')]);
        $this->assertSame(['done', 2, [1, 1]], [($end = $this->json($this->admin('GET', self::PRINTS . '/' . $third['id'])))['status'], $end['written'], [count($tagsOf($p4)), count($tagsOf($p5))]]);

        // Le journal du module garde la création et l'annulation, par qui
        $journal = $this->db->fetchAllAssociative("SELECT type, user_id, data FROM loss_journal WHERE type LIKE 'PRINT_JOB_%' AND CAST(data AS jsonb)->>'jobId' = ? ORDER BY id", [(string) $id]);
        $this->assertSame(['PRINT_JOB_CREATED', 'PRINT_JOB_CANCELLED'], array_column($journal, 'type'));
        $this->assertSame([['jobId' => $id, 'deviceId' => $printer, 'lines' => 3, 'withPrice' => true], ['jobId' => $id, 'lines' => 1]], array_map(fn (array $j): array => json_decode((string) $j['data'], true), $journal));
        $this->assertNotNull($journal[0]['user_id']);
        // En anglais
        $this->assertSame(['Cancelled', 'Written', 'Write refused by the tag'], [($en = $this->json($this->admin('GET', self::PRINTS . "/$id?locale=en")))['statusLabel'], $en['lineItems'][0]['statusLabel'], $en['lineItems'][1]['reasonLabel']]);
    }

    public function testAWrittenTagThatCanNoLongerGoOnItsPieceClosesItsLineInFailure(): void
    {
        $variant = $this->article('Veston');
        [$full, $gone, $taken, $mine, $other] = $this->pieces($variant, 5);
        $bareVariant = $this->article('Foulard', 'B');
        [$bare] = $this->pieces($bareVariant, 1);
        [, $token, $devices] = $this->enrolled('Atelier', [['serial' => "IMPR-$this->s", 'kind' => 'printer', 'status' => 'ok']]);
        $job = $this->json($this->admin('POST', self::PRINTS, ['deviceId' => $devices["IMPR-$this->s"], 'pieceIds' => [$full, $gone, $taken, $mine, $bare]]), 201);
        $lines = array_column($job['lineItems'], null, 'position');
        $bareLine = $lines[5];
        unset($lines[5]);
        $put = fn (int $piece, string $identifier, string $technology): array => $this->json($this->admin('POST', self::BASE . '/puces/associer', ['pieceId' => $piece, 'identifier' => $identifier, 'technology' => $technology]));
        $tags = fn (int $piece): array => $this->json($this->admin('GET', self::BASE . "/pieces/$piece"))['tags'];
        // Entre la création du travail et l'impression, le gérant a continué à travailler : une pièce a reçu quatre puces à la main,
        // une autre a été retirée, l'identifiant d'une ligne a été posé à la main sur une AUTRE pièce, et celui d'une autre sur SA pièce
        foreach (['01', '02', '03', '04'] as $n) {
            $put($full, '04' . $this->s . 'CC00' . $n, 'nfc');
        }
        $this->json($this->admin('POST', self::BASE . "/pieces/$gone/retirer", ['reason' => 'lost', 'confirm' => true]));
        // retirer une pièce clôt aussitôt sa ligne : rien ne sera imprimé pour elle, et le travail n'attend pas après elle
        $this->assertSame(['failed', 'piece_retired'], [($early = $this->json($this->admin('GET', self::PRINTS . '/' . $job['id']))['lineItems'][1])['status'], $early['reason']]);
        // … et un article dont on retire le code-barres n'a plus d'étiquette à imprimer : sa ligne attend, sans occuper la file
        $this->db->executeStatement('UPDATE product_variant SET barcode = NULL WHERE id = ?', [$bareVariant]);
        $put($other, $lines[3]['identifier'], 'uhf');
        $own = $put($mine, $lines[4]['identifier'], 'uhf')['tag']['id'];
        // ni la ligne de la pièce retirée, ni celle de l'article sans code-barres ne sont servies au boîtier
        $this->assertSame([[$lines[1]['id'], $lines[3]['id'], $lines[4]['id']]], array_map(fn (array $j): array => array_column($j['lines'], 'id'), $this->json($this->box('GET', '/travaux', null, $token))['jobs']));
        // L'imprimante écrit quand même ses quatre étiquettes : aucune puce n'est posée à tort, chaque ligne dit ce qu'il en est
        $write = fn (array $l): array => $this->fact($token, 'TAG_WRITTEN', "IMPR-$this->s", ['line' => $l['id'], 'identifier' => $l['identifier']]);
        $this->assertSame([['accepted', null], ['duplicate', null], ['accepted', null], ['accepted', null]], array_map($write, array_values($lines)), 'la ligne de la pièce retirée est déjà close : reçue, rien de plus');
        // le boîtier renvoie ses faits (réponse perdue) : des doublons, y compris pour les écritures que la plateforme a closes en échec
        $this->assertSame(array_fill(0, 4, ['duplicate', null]), array_map($write, array_values($lines)));
        $this->assertSame(['refused', 'PRINT_LINE_CLOSED'], $this->fact($token, 'TAG_WRITE_FAILED', "IMPR-$this->s", ['line' => $lines[1]['id'], 'reason' => 'write_refused']), 'un échec dit par le boîtier après coup, lui, est contraire');
        $sheet = $this->json($this->admin('GET', self::PRINTS . '/' . $job['id']));
        $this->assertSame(['running', 1, 3, 1, $bareLine['id']], [$sheet['status'], $sheet['written'], $sheet['failed'], $sheet['waiting'], $sheet['lineItems'][4]['id']]);
        array_pop($sheet['lineItems']);
        $this->assertSame([['failed', 'tag_limit', 'La pièce porte déjà le nombre maximal de puces', null], ['failed', 'piece_retired', 'Pièce retirée entre-temps', null],
            ['failed', 'identifier_taken', 'Identifiant déjà connu sur le site', null], ['written', null, null, $own]],
            array_map(fn (array $l): array => [$l['status'], $l['reason'], $l['reasonLabel'], $l['tagId']], $sheet['lineItems']));
        $this->assertSame([4, 0, 1, 1], [count($tags($full)), count($tags($taken)), count($tags($mine)), count($tags($other))], 'ni cinquième puce, ni puce sur la mauvaise pièce, ni doublon');
        $this->assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_piece_event WHERE source = 'box' AND type = 'TAG_ASSOCIATED' AND piece_id IN (?, ?, ?, ?, ?)", [$full, $gone, $taken, $mine, $other]),
            'aucune de ces lignes n\'a posé de puce par le boîtier');
    }

    public function testThePasswordOfTheTagsIsGivenToTheBoxOnlyAndIsWrittenNowhere(): void
    {
        $variant = $this->article('Écharpe');
        [$p1, $p2] = $this->pieces($variant, 2);
        [$boxId, $token, $devices] = $this->enrolled('Comptoir', [['serial' => "IMPR-$this->s", 'kind' => 'printer', 'status' => 'ok']]);
        $printer = $devices["IMPR-$this->s"];
        $seen = []; // tout ce que l'administrateur reçoit
        $admin = function (string $method, string $path, ?array $body = null, int $status = 200) use (&$seen): array {
            $response = $this->admin($method, $path, $body);
            $seen[] = (string) $response->getContent() . json_encode($response->headers->all());

            return $this->json($response, $status);
        };
        $secrets = fn (): array => $this->db->fetchAllAssociative('SELECT rank, key_rank, cipher FROM loss_tag_secret ORDER BY rank');
        $job = $admin('POST', self::PRINTS, ['deviceId' => $printer, 'pieceIds' => [$p1]], 201);
        // Un mot de passe par site, tiré une fois, rangé chiffré avec le rang de sa clé
        $stored = $secrets();
        $this->assertSame([1, 1, 1], [count($stored), (int) $stored[0]['rank'], (int) $stored[0]['key_rank']]);
        $password = $this->json($this->box('GET', '/travaux', null, $token))['jobs'][0]['access']['password'];
        $this->assertMatchesRegularExpression('/\A[0-9A-F]{8}\z/', $password);
        $this->assertNotSame('00000000', $password);
        $vault = new LossTagVault('1:' . self::tagKey(1));
        $this->assertSame($password, $vault->decrypt(1, (string) $stored[0]['cipher'], 'loss-tag-secret:' . MV_TEST_TENANT_CODE . ':1'), 'le chiffre rangé est bien ce mot de passe, sous la clé du rang 1');
        $this->assertNull($vault->decrypt(1, (string) $stored[0]['cipher'], 'loss-tag-secret:un-autre-site:1'), 'le chiffre est lié à ce site : copié dans une autre base, il ne se relit pas');
        $this->assertNull($vault->decrypt(1, (string) $stored[0]['cipher'], 'loss-tag-secret:' . MV_TEST_TENANT_CODE . ':2'), '… et à ce rang');
        $this->assertStringNotContainsStringIgnoringCase($password, (string) $stored[0]['cipher']);
        $this->assertStringNotContainsString(self::tagKey(1), (string) $stored[0]['cipher']);
        // Un second travail emploie le même mot de passe : rien de plus n'est tiré
        $other = $admin('POST', self::PRINTS, ['deviceId' => $printer, 'pieceIds' => [$p2]], 201);
        $this->assertSame($stored, $secrets());
        $served = $this->json($this->box('GET', '/travaux', null, $token))['jobs'];
        $this->assertSame([[1, $password], [1, $password]], array_map(fn (array $j): array => [$j['access']['rank'], $j['access']['password']], $served));
        // Le boîtier travaille ; l'administrateur regarde tout ce qu'il peut regarder
        $this->fact($token, 'TAG_WRITTEN', "IMPR-$this->s", ['line' => $job['lineItems'][0]['id'], 'identifier' => $job['lineItems'][0]['identifier']]);
        $this->fact($token, 'TAG_WRITE_FAILED', "IMPR-$this->s", ['line' => $other['lineItems'][0]['id'], 'reason' => 'verify_failed']);
        foreach ([self::PRINTS, self::PRINTS . '/' . $job['id'], self::PRINTS . '/' . $other['id'], self::BOXES, self::BOXES . "/$boxId", self::BASE . '/sante', self::BASE . '/installation', self::BASE . '/materiel',
            self::BASE . "/pieces/$p1", self::BASE . '/pieces', self::BASE . '/reglages', self::BASE] as $path) {
            $admin('GET', $path);
        }
        $admin('POST', self::PRINTS . '/' . $other['id'] . '/annuler');
        $settings = $this->box('GET', '/reglages', null, $token);
        $beat = $this->box('POST', '/signe-de-vie', ['now' => gmdate('Y-m-d\TH:i:s\Z'), 'machine' => self::MACHINE, 'devices' => [['serial' => "IMPR-$this->s", 'kind' => 'printer', 'status' => 'ok']],
            'lastServerTime' => $this->answered[$token]], $token);
        $this->assertSame([200, 200], [$settings->getStatusCode(), $beat->getStatusCode()]);
        // … et ce que la base garde : journal du module, faits des boîtiers et des pièces, travaux, lignes, puces
        $kept = '';
        foreach (['loss_journal', 'loss_box_event', 'loss_piece_event', 'loss_print_job', 'loss_print_job_line', 'loss_tag', 'loss_box', 'loss_device', 'loss_tag_secret'] as $table) {
            $kept .= json_encode($this->db->fetchAllAssociative("SELECT * FROM $table ORDER BY id DESC LIMIT 200"));
        }
        foreach (['ce que l\'administrateur reçoit' => implode('', $seen), 'les réglages et le signe de vie du boîtier' => $settings->getContent() . $beat->getContent(), 'la base' => $kept] as $where => $text) {
            $this->assertStringNotContainsStringIgnoringCase($password, (string) $text, "le mot de passe n'apparaît pas dans : $where");
            $this->assertStringNotContainsString(self::tagKey(1), (string) $text, "la clé de chiffrement n'apparaît pas dans : $where");
        }
        $this->assertStringNotContainsString('password', implode('', $seen), 'aucune réponse à l\'administrateur n\'a de champ de mot de passe');
        $log = dirname(__DIR__, 3) . '/var/log/test.log';
        if (is_file($log)) { // la fin du journal du serveur (les 30 derniers Mo) : aucun champ de mot de passe qui porte celui-ci, sous aucune forme d'écriture
            $tail = (string) file_get_contents($log, false, null, max(0, filesize($log) - 30000000));
            $this->assertDoesNotMatchRegularExpression('/password\W{0,8}' . $password . '/i', $tail, 'ni dans le journal du serveur');
            $this->assertStringNotContainsString(self::tagKey(1), $tail, 'la clé de chiffrement n\'est pas dans le journal du serveur');
        }
        // Le journal du module dit qu'un mot de passe a été tiré : son rang, rien d'autre
        $drawn = $this->db->fetchAllAssociative("SELECT data, user_id FROM loss_journal WHERE type = 'TAG_SECRET_CREATED'");
        $this->assertSame([1, ['rank' => 1, 'keyRank' => 1]], [count($drawn), json_decode((string) $drawn[0]['data'], true)]);
        // Changement de clé : l'ancienne reste lisible sous son rang, la nouvelle chiffrerait (rang le plus grand)
        $this->tagKeys(self::tagKey(1), self::tagKey(2));
        $third = $this->pieces($variant, 1)[0];
        $admin('POST', self::PRINTS, ['deviceId' => $printer, 'pieceIds' => [$third]], 201);
        $this->assertSame($password, $this->json($this->box('GET', '/travaux', null, $token))['jobs'][0]['access']['password']);
        $this->assertSame($stored, $secrets());
    }

    public function testWithoutTheEncryptionKeyNoRealJobIsCreatedAndTheHealthBoardSaysSo(): void
    {
        $variant = $this->article('Gilet');
        [$p1, $p2, $p3] = $this->pieces($variant, 3);
        [, $token, $devices] = $this->enrolled('Réserve', [['serial' => "IMPR-$this->s", 'kind' => 'printer', 'status' => 'ok']]);
        $printer = $devices["IMPR-$this->s"];
        $body = fn (int $piece): array => ['deviceId' => $printer, 'pieceIds' => [$piece]];
        $line = fn (): array => array_column($this->json($this->admin('GET', self::BASE . '/sante'))['devices'], null, 'code')["printer-$printer"];
        $ready = fn (): array => $this->json($this->admin('GET', self::PRINTS))['ready'];
        $jobs = fn (): int => (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_print_job');
        $before = $jobs();

        // La variable n'est pas posée : refus net, rien d'écrit, l'écran et le tableau de santé le disent
        $this->tagKeys(null);
        $refused = $this->json($this->admin('POST', self::PRINTS, $body($p1)), 409);
        $this->assertSame(['PRINT_KEY_MISSING', 'LOSS_TAG_KEYS', 'L\'écriture des puces n\'est pas encore activée sur ce serveur. Contactez la plateforme.'], [$refused['code'], $refused['variable'], $refused['message']]);
        $this->assertSame($before, $jobs());
        $this->assertSame([false, 'PRINT_KEY_MISSING'], [$ready()['ok'], $ready()['reason']]);
        $this->assertStringContainsString('LOSS_TAG_KEYS', (string) $ready()['message']);
        $this->assertSame(['pending', $ready()['message']], [$line()['state'], $line()['hint']]);
        // Posée mais illisible : pas une clé de 32 octets, pas de rang, un rang donné deux fois
        foreach (['1:' . base64_encode('trop court'), self::tagKey(1), '0:' . self::tagKey(1), '1:' . self::tagKey(1) . ',1:' . self::tagKey(2), '1:pas du base64 !'] as $bad) {
            $this->tagKeysRaw($bad);
            $this->assertSame('PRINT_KEY_INVALID', $this->json($this->admin('POST', self::PRINTS, $body($p1)), 409)['code'], $bad === self::tagKey(1) ? 'sans rang' : 'clé illisible');
        }
        $this->assertSame([$before, 'PRINT_KEY_INVALID', 'pending'], [$jobs(), $ready()['reason'], $line()['state']]);

        // La bonne clé. Mais le site de référence, que tout site neuf copie, ne reçoit jamais de vrai travail : il ne doit porter aucun
        // mot de passe de puces (le chiffre est lié au site : un site né de cette copie ne pourrait plus imprimer)
        $this->tagKeys(self::tagKey(1));
        $this->assertTrue($ready()['ok']);
        $template = [$_ENV['TENANT_TEMPLATE_DB'] ?? null, $_SERVER['TENANT_TEMPLATE_DB'] ?? null];
        try {
            $_ENV['TENANT_TEMPLATE_DB'] = $_SERVER['TENANT_TEMPLATE_DB'] = MV_TEST_TENANT_DB;
            $this->assertSame('PRINT_ON_REFERENCE_SITE', $this->json($this->admin('POST', self::PRINTS, $body($p1)), 409)['code']);
            $this->assertSame($before, $jobs());
        } finally {
            foreach (['_ENV' => $template[0], '_SERVER' => $template[1]] as $where => $value) {
                if ($value === null) {
                    unset($GLOBALS[$where]['TENANT_TEMPLATE_DB']);
                } else {
                    $GLOBALS[$where]['TENANT_TEMPLATE_DB'] = $value;
                }
            }
        }
        // Sur un site ordinaire, le travail se crée. Puis une AUTRE clé sous le même rang : le mot de passe rangé ne se relit plus
        $job = $this->json($this->admin('POST', self::PRINTS, $body($p1)), 201);
        $this->assertCount(1, $this->json($this->box('GET', '/travaux', null, $token))['jobs']);
        // le boîtier est servi par 50 lignes : un grand travail vient en plusieurs appels
        $big = $this->json($this->admin('POST', self::PRINTS, ['deviceId' => $printer, 'pieceIds' => $this->pieces($variant, 55)]), 201);
        $served = $this->json($this->box('GET', '/travaux', null, $token));
        $this->assertSame([[1, 49], true], [array_map(fn (array $j): int => count($j['lines']), $served['jobs']), $served['more']]);
        $this->json($this->admin('POST', self::PRINTS . '/' . $big['id'] . '/annuler'));
        $this->assertSame([[1], false], [array_map(fn (array $j): int => count($j['lines']), ($few = $this->json($this->box('GET', '/travaux', null, $token)))['jobs']), $few['more']]);
        $this->tagKeys(self::tagKey(2));
        $this->assertSame('PRINT_KEY_MISMATCH', $this->json($this->admin('POST', self::PRINTS, $body($p2)), 409)['code']);
        $this->assertSame([false, 'PRINT_KEY_MISMATCH', 'pending'], [$ready()['ok'], $ready()['reason'], $line()['state']]);
        $this->assertSame([[], false], [($none = $this->json($this->box('GET', '/travaux', null, $token)))['jobs'], $none['more']], 'sans mot de passe relisible, le travail attend : il n\'est pas servi sans lui, et rien n\'est annoncé');
        $this->assertSame('waiting', $this->json($this->admin('GET', self::PRINTS . '/' . $job['id']))['status']);
        // la variable retirée : pareil, le travail attend ; reposée, il repart
        $this->tagKeys(null);
        $this->assertSame([], $this->json($this->box('GET', '/travaux', null, $token))['jobs']);
        $this->tagKeys(self::tagKey(1));
        $this->assertCount(1, $this->json($this->box('GET', '/travaux', null, $token))['jobs']);

        // Le mode exercice n'emploie aucun mot de passe : il marche sans la variable
        $this->tagKeys(null);
        $sim = $this->json($this->admin('POST', self::EX . '/boitiers'), 201)['box']['id'];
        $this->json($this->admin('POST', self::EX . '/pieces', ['variantId' => $variant, 'count' => 2]), 201);
        $played = $this->json($this->admin('POST', self::EX . "/boitiers/$sim/scenarios", ['scenario' => 'print_ok']));
        $this->assertSame(2, $played['written']);
        $this->assertSame('ok', array_column($this->json($this->admin('GET', self::EX . '/sante'))['devices'], null, 'kind')['printer']['state'], 'le tableau d\'exercice ne demande aucune clé');
        $this->assertSame([true, null], [($r = $this->json($this->admin('GET', self::EX . '/impressions'))['ready'])['ok'], $r['reason']]);
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_print_job WHERE exercise AND secret_rank IS NOT NULL'));
        $this->assertSame($p3, $p3); // (la troisième pièce reste libre pour les essais suivants du même lancement)
    }

    public function testTheSimulatedPrinterPlaysItsScenariosAndIsErasedWithTheExercise(): void
    {
        $variant = $this->article('Bonnet');
        $box = $this->json($this->admin('POST', self::EX . '/boitiers'), 201);
        $sim = $box['box']['id'];
        $this->assertContains('printer', array_column($box['box']['devices'], 'kind'), 'le boîtier simulé a son imprimante à puce');
        $this->assertSame(['print_ok', 'print_refused', 'print_no_labels'], array_slice(array_column($box['scenarios'], 'code'), -3));
        $this->assertSame(['Étiquettes imprimées', 'Écriture refusée', 'Plus d\'étiquettes'], array_slice(array_column($box['scenarios'], 'label'), -3));
        $play = fn (string $scenario, int $status = 200): array => $this->json($this->admin('POST', self::EX . "/boitiers/$sim/scenarios", ['scenario' => $scenario]), $status);
        $line = fn (): array => array_column($this->json($this->admin('GET', self::EX . '/sante'))['devices'], null, 'kind')['printer'];
        $this->assertSame(['pending', 'L\'imprimante simulée n\'a encore rien écrit. Jouez le scénario « Étiquettes imprimées ».'], [$line()['state'], $line()['hint']]);

        // Sans pièce d'exercice sans puce, rien à imprimer
        $this->assertSame('EXERCISE_NO_UNTAGGED_PIECE', $play('print_ok', 409)['code']);
        $created = array_column($this->json($this->admin('POST', self::EX . '/pieces', ['variantId' => $variant, 'count' => 8]), 201)['pieces'], 'id');
        // Étiquettes imprimées : trois pièces reçoivent leur puce simulée
        $ok = $play('print_ok');
        $this->assertSame(['print_ok', 3], [$ok['scenario'], $ok['written']]);
        $job = $this->json($this->admin('GET', self::EX . '/impressions/' . $ok['printJobId']));
        $this->assertSame(['done', 3, 3, 0, 0, true, 'SIM-IMPRIMANTE'], [$job['status'], $job['lines'], $job['written'], $job['failed'], $job['waiting'], $job['exercise'], $job['printer']['serial']]);
        foreach ($job['lineItems'] as $l) {
            $this->assertMatchesRegularExpression('/\ASIM-[0-9A-F]{12}\z/', $l['identifier']);
            $this->assertSame('written', $l['status']);
        }
        $this->assertSame(array_slice($created, 0, 3), array_map(fn (array $l): int => $l['piece']['id'], $job['lineItems']));
        $tags = $this->json($this->admin('GET', self::EX . '/pieces/' . $created[0]))['tags'];
        $this->assertSame([$job['lineItems'][0]['identifier'], 'simulated'], [$tags[0]['identifier'], $tags[0]['technology']]);
        $this->assertSame('ok', $line()['state']);
        // Les deux mondes ne se croisent pas : le travail d'exercice est inconnu des vraies routes, et absent de la vraie liste
        $this->assertSame('PRINT_JOB_NOT_FOUND', $this->json($this->admin('GET', self::PRINTS . '/' . $ok['printJobId']), 404)['code']);
        $this->assertSame('PRINT_JOB_NOT_FOUND', $this->json($this->admin('POST', self::PRINTS . '/' . $ok['printJobId'] . '/annuler'), 404)['code']);
        $this->assertNotContains($ok['printJobId'], array_column($this->json($this->admin('GET', self::PRINTS))['items'], 'id'));
        $this->assertSame([$ok['printJobId']], array_column($this->json($this->admin('GET', self::EX . '/impressions'))['items'], 'id'));
        $this->assertSame('PRINTER_NOT_FOUND', $this->json($this->admin('POST', self::PRINTS, ['deviceId' => $job['printer']['deviceId'], 'pieceIds' => $this->pieces($variant, 1)]), 404)['code'],
            'une imprimante simulée ne reçoit pas de vrai travail');
        // Écriture refusée : la première étiquette échoue, les autres passent ; plus d'étiquettes : la première passe, puis plus rien
        $refused = $play('print_refused');
        $this->assertSame([2, [['failed', 'write_refused'], ['written', null], ['written', null]]],
            [$refused['written'], array_map(fn (array $l): array => [$l['status'], $l['reason']], $this->json($this->admin('GET', self::EX . '/impressions/' . $refused['printJobId']))['lineItems'])]);
        $empty = $play('print_no_labels');
        $rows = $this->json($this->admin('GET', self::EX . '/impressions/' . $empty['printJobId']));
        $this->assertSame([1, 'done', [['written', null, null], ['failed', 'out_of_labels', 'Plus d\'étiquettes'], ['failed', 'out_of_labels', 'Plus d\'étiquettes']]],
            [$empty['written'], $rows['status'], array_map(fn (array $l): array => [$l['status'], $l['reason'], $l['reasonLabel']], $rows['lineItems'])]);
        // la pièce dont l'écriture a été refusée est restée sans puce : elle repasse avant les suivantes
        $this->assertSame($created[3], $rows['lineItems'][0]['piece']['id']);
        // Rien de réel n'a bougé, aucun mot de passe n'a servi
        $this->assertSame([0, 0], array_map('intval', $this->db->fetchNumeric('SELECT (SELECT COUNT(*) FROM loss_print_job WHERE exercise AND secret_rank IS NOT NULL), (SELECT COUNT(*) FROM loss_tag WHERE NOT exercise AND identifier LIKE \'SIM-%\')')));
        // Effacer l'exercice : les travaux simulés partent avec lui
        $this->json($this->admin('DELETE', self::EX . '/boitiers'));
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_print_job WHERE exercise'));
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_print_job_line l JOIN loss_piece p ON p.id = l.piece_id WHERE p.exercise'));
        $this->assertSame([], $this->json($this->admin('GET', self::EX . '/impressions'))['items']);
        // Sans le droit du module (plateforme en service) : seules les lectures d'exercice s'ouvrent, jamais les vrais travaux
        static::getContainer()->get(PlatformModeService::class)->setMode('live', 'test');
        $this->flags(true, false);
        $this->assertSame([200, 404, 403, 403, 403], [$this->admin('GET', self::EX . '/impressions')->getStatusCode(), $this->admin('GET', self::EX . '/impressions/999999999')->getStatusCode(),
            $this->admin('GET', self::PRINTS)->getStatusCode(), $this->admin('POST', self::PRINTS, [])->getStatusCode(), $this->admin('POST', self::PRINTS . '/1/annuler')->getStatusCode()]);
    }

    public function testTheIdentifierAndTheVaultFollowTheirRules(): void
    {
        // L'identifiant : 24 caractères, la marque, le site, le numéro, l'exemplaire, une clé de contrôle
        $a = LossTagEpc::make('boutique-a', 12, 0);
        $this->assertMatchesRegularExpression('/\AF1A7[0-9A-F]{8}0000000C00[0-9A-F]{2}\z/', $a);
        $this->assertSame($a, LossTagEpc::make('boutique-a', 12, 0));
        $this->assertCount(4, array_unique([$a, LossTagEpc::make('boutique-a', 12, 1), LossTagEpc::make('boutique-a', 13, 0), LossTagEpc::make('boutique-b', 12, 0)]));
        $this->assertSame('FFFFFFFFFF', substr(LossTagEpc::make('boutique-a', 4294967295, 255), 12, 10));
        $this->assertTrue(LossTagEpc::isOurs($a, 'boutique-a'));
        $this->assertFalse(LossTagEpc::isOurs($a, 'boutique-b'), 'un autre site');
        $this->assertFalse(LossTagEpc::isOurs(substr($a, 0, 22) . (substr($a, 22) === '00' ? '01' : '00'), 'boutique-a'), 'clé de contrôle fausse');
        $this->assertFalse(LossTagEpc::isOurs('E280' . substr($a, 4), 'boutique-a'), 'une autre marque');
        $this->assertFalse(LossTagEpc::isOurs(substr($a, 0, 20), 'boutique-a'));
        foreach ([['', 1, 0], ['s', 0, 0], ['s', 4294967296, 0], ['s', 1, -1], ['s', 1, 256]] as [$site, $number, $copy]) {
            try {
                LossTagEpc::make($site, $number, $copy);
                $this->fail("hors bornes accepté : $site $number $copy");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        // Le coffre : la variable, ses rangs, le chiffrement
        $this->assertSame([LossTagVault::MISSING, null], [($none = new LossTagVault(null))->state(), $none->currentRank()]);
        $this->assertSame(LossTagVault::MISSING, (new LossTagVault('   '))->state());
        foreach ([self::tagKey(1), '1:' . base64_encode(str_repeat('k', 31)), 'a:' . self::tagKey(1), '0:' . self::tagKey(1), '1:' . self::tagKey(1) . ',1:' . self::tagKey(2), '1:' . self::tagKey(1) . ',', '1:@@@'] as $bad) {
            $this->assertSame(LossTagVault::INVALID, (new LossTagVault($bad))->state(), $bad);
        }
        $one = new LossTagVault(' 1:' . self::tagKey(1) . ' ');
        $two = new LossTagVault('2:' . self::tagKey(2) . ', 1:' . self::tagKey(1));
        $this->assertSame([LossTagVault::READY, 1, LossTagVault::READY, 2, true, false], [$one->state(), $one->currentRank(), $two->state(), $two->currentRank(), $two->has(1), $one->has(2)]);
        [$rank, $cipher] = $one->encrypt('0A1B2C3D', 'site-a:1');
        $this->assertSame([1, '0A1B2C3D', '0A1B2C3D'], [$rank, $one->decrypt(1, $cipher, 'site-a:1'), $two->decrypt(1, $cipher, 'site-a:1')], 'la clé du rang rangé déchiffre, même après un changement de clé');
        $this->assertNotSame($cipher, $one->encrypt('0A1B2C3D', 'site-a:1')[1], 'un chiffre ne se répète pas');
        $this->assertSame(2, $two->encrypt('0A1B2C3D', 'site-a:1')[0], 'le plus grand rang chiffre');
        $this->assertNull($one->decrypt(1, $cipher, 'site-b:1'), 'un chiffre copié sur un autre site ne se relit pas');
        $this->assertNull($one->decrypt(1, $cipher, 'site-a:2'), 'ni sous un autre rang de mot de passe');
        $this->assertNull($two->decrypt(2, $cipher, 'site-a:1'), 'pas la bonne clé');
        $this->assertNull($one->decrypt(2, $cipher, 'site-a:1'), 'clé de ce rang absente');
        $this->assertNull($one->decrypt(1, 'zz', 'site-a:1'));
        $this->assertNull($one->decrypt(1, substr($cipher, 0, 20), 'site-a:1'));
        $this->assertNull($one->decrypt(1, $cipher . '00', 'site-a:1'), 'chiffre abîmé');
        try {
            (new LossTagVault(null))->encrypt('0A1B2C3D', 'site-a:1');
            $this->fail('chiffrer sans clé');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }
        $drawn = array_map(fn (): string => LossTagVault::draw(), range(1, 50));
        $this->assertSame(50, count(array_filter($drawn, fn (string $p): bool => preg_match('/\A[0-9A-F]{8}\z/', $p) === 1 && $p !== '00000000')));
        $this->assertGreaterThan(45, count(array_unique($drawn)));
    }

    public function testTheDatabaseGuardsPrintJobsItselfAndTheMigrationReplays(): void
    {
        require_once dirname(__DIR__, 3) . '/migrations/Version20270118100000.php';
        foreach (\DoctrineMigrations\Version20270118100000::STATEMENTS as $sql) {
            $this->db->executeStatement($sql); // rejouable : aucune erreur, rien de perdu
        }
        $variant = $this->article('Châle');
        [$p1, $p2] = $this->pieces($variant, 2);
        [$boxId, $token, $devices] = $this->enrolled('Garde', [['serial' => "IMPR-$this->s", 'kind' => 'printer', 'status' => 'ok'], ['serial' => "TAPIS-$this->s", 'kind' => 'counter_mat', 'status' => 'ok']]);
        [$printer, $mat] = [$devices["IMPR-$this->s"], $devices["TAPIS-$this->s"]];
        $job = $this->json($this->admin('POST', self::PRINTS, ['deviceId' => $printer, 'pieceIds' => [$p1, $p2]]), 201);
        [$l1, $l2] = array_column($job['lineItems'], 'id');
        $refusedBy = function (string $sql, array $params, string $why): void {
            $this->db->executeStatement('SAVEPOINT garde');
            try {
                $this->db->executeStatement($sql, $params);
                $this->fail("la base a accepté : $why");
            } catch (\Doctrine\DBAL\Exception $e) {
                $this->assertStringContainsString('prévention des pertes', $e->getMessage(), $why);
            } finally {
                $this->db->executeStatement('ROLLBACK TO SAVEPOINT garde');
            }
        };
        $this->db->beginTransaction();
        $insertJob = 'INSERT INTO loss_print_job (box_id, device_id, exercise, secret_rank, created_at) VALUES (?, ?, CAST(? AS boolean), ?, NOW())';
        $refusedBy($insertJob, [$boxId, $printer, 'false', null], 'un vrai travail sans rang de mot de passe');
        $refusedBy($insertJob, [$boxId, $printer, 'false', 99], 'un vrai travail au rang d\'un mot de passe qui n\'existe pas');
        $refusedBy($insertJob, [$boxId, $printer, 'true', null], 'un travail d\'exercice sur un vrai boîtier');
        $refusedBy($insertJob, [$boxId, $printer, 'true', 1], 'un travail d\'exercice avec un mot de passe');
        $refusedBy($insertJob, [$boxId, $mat, 'false', 1], 'un travail sur un tapis');
        $simBox = (int) $this->db->fetchOne("INSERT INTO loss_box (name, status, exercise, created_at, updated_at) VALUES ('Garde simulée', 'active', true, NOW(), NOW()) RETURNING id");
        $simPrinter = (int) $this->db->fetchOne("INSERT INTO loss_device (box_id, serial, kind, status, exercise, first_seen_at, last_seen_at) VALUES (?, 'SIM-GARDE', 'printer', 'ok', true, NOW(), NOW()) RETURNING id", [$simBox]);
        $refusedBy($insertJob, [$simBox, $simPrinter, 'true', 1], 'un travail d\'exercice, sur une imprimante simulée, avec un mot de passe');
        $refusedBy($insertJob, [$simBox, $simPrinter, 'false', 1], 'un vrai travail sur une imprimante simulée');
        $this->assertSame(1, $this->db->executeStatement($insertJob, [$simBox, $simPrinter, 'true', null]), 'un travail d\'exercice sans mot de passe : permis');
        $refusedBy("UPDATE loss_print_job SET status = 'en panne' WHERE id = ?", [$job['id']], 'un état inconnu');
        $refusedBy('UPDATE loss_print_job SET device_id = ? WHERE id = ?', [$mat, $job['id']], 'l\'imprimante d\'un travail changée');
        $refusedBy('UPDATE loss_print_job SET secret_rank = 2 WHERE id = ?', [$job['id']], 'le rang du mot de passe d\'un travail changé');
        $refusedBy('UPDATE loss_print_job SET with_price = true WHERE id = ?', [$job['id']], 'le prix d\'un travail changé');
        $refusedBy("UPDATE loss_print_job_line SET status = 'written', done_at = NOW() WHERE id = ?", [$l1], 'une ligne écrite sans puce');
        $refusedBy("UPDATE loss_print_job_line SET status = 'failed', done_at = NOW() WHERE id = ?", [$l1], 'une ligne en échec sans motif');
        $refusedBy("UPDATE loss_print_job_line SET status = 'cancelled' WHERE id = ?", [$l1], 'une ligne close sans heure');
        $refusedBy("UPDATE loss_print_job_line SET identifier = 'AAAA' WHERE id = ?", [$l1], 'l\'identifiant d\'une ligne changé');
        $refusedBy('UPDATE loss_print_job_line SET piece_id = ? WHERE id = ?', [$p2, $l1], 'la pièce d\'une ligne changée');
        $refusedBy('INSERT INTO loss_print_job_line (job_id, position, piece_id, identifier, status, reason, done_at) VALUES (?, 9, ?, ?, \'failed\', \'x\', NOW())', [$job['id'], $p1, 'F1A7' . str_repeat('0', 20)], 'une ligne qui ne naît pas en attente');
        $exercisePiece = (int) $this->db->fetchOne("INSERT INTO loss_piece (number, variant_id, product_id, status, exercise, created_at, updated_at) SELECT -900000 - (random() * 90000)::int, variant_id, product_id, 'created', true, NOW(), NOW() FROM loss_piece WHERE id = ? RETURNING id", [$p1]);
        $refusedBy('INSERT INTO loss_print_job_line (job_id, position, piece_id, identifier) VALUES (?, 9, ?, ?)', [$job['id'], $exercisePiece, 'F1A7' . str_repeat('1', 20)], 'une pièce d\'exercice dans un vrai travail');
        // la puce d'une ligne écrite est posée sur SA pièce et porte SON identifiant
        $foreign = (int) $this->db->fetchOne("INSERT INTO loss_tag (identifier, raw, technology, status, piece_id, exercise, associated_at, created_at, updated_at) VALUES (?, ?, 'uhf', 'associated', ?, false, NOW(), NOW(), NOW()) RETURNING id",
            ['E2' . str_repeat('A', 22), 'E2' . str_repeat('A', 22), $p2]);
        $refusedBy("UPDATE loss_print_job_line SET status = 'written', tag_id = ?, done_at = NOW() WHERE id = ?", [$foreign, $l1], 'la puce d\'une autre pièce');
        $misplaced = (int) $this->db->fetchOne("INSERT INTO loss_tag (identifier, raw, technology, status, piece_id, exercise, associated_at, created_at, updated_at) VALUES (?, ?, 'uhf', 'associated', ?, false, NOW(), NOW(), NOW()) RETURNING id",
            [$job['lineItems'][0]['identifier'], $job['lineItems'][0]['identifier'], $p2]);
        $refusedBy("UPDATE loss_print_job_line SET status = 'written', tag_id = ?, done_at = NOW() WHERE id = ?", [$misplaced, $l1], 'le bon identifiant, mais posé sur une autre pièce');
        $refusedBy("UPDATE loss_print_job_line SET status = 'written', tag_id = ?, done_at = NOW() WHERE id = ?", [$foreign, $l2], 'une puce de la pièce qui porte un autre identifiant');
        $worn = (int) $this->db->fetchOne("INSERT INTO loss_tag (identifier, raw, technology, status, piece_id, exercise, associated_at, created_at, updated_at) VALUES (?, ?, 'uhf', 'associated', ?, false, NOW(), NOW(), NOW()) RETURNING id",
            [$job['lineItems'][1]['identifier'], $job['lineItems'][1]['identifier'], $p2]);
        $this->provider->getEntityManager()->getRepository(LossPiece::class)->retireTag($worn, 'error', gmdate('Y-m-d H:i:s'));
        $refusedBy("UPDATE loss_print_job_line SET status = 'written', tag_id = ?, done_at = NOW() WHERE id = ?", [$worn, $l2], 'la bonne puce de la bonne pièce, mais retirée');
        $this->db->rollBack();
        // une ligne close ne change plus ; un travail fini ou annulé ne repart pas
        $this->fact($token, 'TAG_WRITE_FAILED', "IMPR-$this->s", ['line' => $l1, 'reason' => 'tag_unreadable']);
        $this->json($this->admin('POST', self::PRINTS . '/' . $job['id'] . '/annuler'));
        $this->db->beginTransaction();
        $refusedBy("UPDATE loss_print_job_line SET reason = 'write_refused' WHERE id = ?", [$l1], 'le motif d\'une ligne close changé');
        $refusedBy("UPDATE loss_print_job_line SET status = 'waiting', reason = NULL, done_at = NULL WHERE id = ?", [$l1], 'une ligne close remise en attente');
        $refusedBy("UPDATE loss_print_job_line SET status = 'waiting', done_at = NULL WHERE id = ?", [$l2], 'une ligne annulée remise en attente');
        $refusedBy("UPDATE loss_print_job SET status = 'running' WHERE id = ?", [$job['id']], 'un travail annulé qui repart');
        // le mot de passe rangé : ni supprimé, ni vidé, son rang figé ; son chiffre et le rang de sa clé peuvent changer (changement de clé)
        $refusedBy('DELETE FROM loss_tag_secret', [], 'un mot de passe supprimé');
        $refusedBy('TRUNCATE loss_tag_secret', [], 'la table des mots de passe vidée');
        $refusedBy('UPDATE loss_tag_secret SET rank = 7', [], 'le rang d\'un mot de passe changé');
        $refusedBy("UPDATE loss_tag_secret SET cipher = ''", [], 'un chiffre vide');
        $refusedBy('UPDATE loss_tag_secret SET key_rank = 0', [], 'un rang de clé nul');
        $this->assertSame(1, $this->db->executeStatement('UPDATE loss_tag_secret SET key_rank = 2, cipher = cipher WHERE rank = 1'), 'rechiffré sous une autre clé : permis');
        $this->db->rollBack();
        // une pièce n'attend que dans un travail à la fois, un identifiant n'est donné qu'à une ligne : index uniques
        $indexes = $this->db->fetchFirstColumn("SELECT indexname FROM pg_indexes WHERE tablename IN ('loss_print_job', 'loss_print_job_line', 'loss_tag_secret') ORDER BY indexname");
        $this->assertSame(['idx_loss_print_job_box', 'idx_loss_print_line_piece', 'loss_print_job_line_pkey', 'loss_print_job_pkey', 'loss_tag_secret_pkey', 'uniq_loss_print_line_identifier', 'uniq_loss_print_line_open_piece',
            'uniq_loss_print_line_position', 'uniq_loss_tag_secret_rank'], $indexes);
        $this->assertSame(23, count(\DoctrineMigrations\Version20270118100000::STATEMENTS), 'la migration et son script portent les mêmes instructions');
    }

    // ----- aides -----

    /** Une variante d'un article créé pour ce test, avec son code-barres (une étiquette en a besoin) */
    private function article(string $name, string $suffix = ''): int
    {
        $slug = 'l3c-' . bin2hex(random_bytes(4));
        $this->db->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, is_web, is_pos) VALUES (nextval('product_id_seq'), :n, 3000, false, 0, NOW(), :s, 'standard', false, true)",
            ['n' => "$name L3C-$this->s", 's' => $slug]);
        $product = (int) $this->db->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]);

        return (int) $this->db->fetchOne("INSERT INTO product_variant (id, product_id, stock_quantity, barcode) VALUES (nextval('product_variant_id_seq'), ?, 5, ?) RETURNING id", [$product, "L3C$this->s$suffix"]);
    }

    /** @return list<int> de vraies pièces de cette variante, sans puce */
    private function pieces(int $variant, int $count): array
    {
        return array_column($this->json($this->admin('POST', self::BASE . '/pieces', ['variantId' => $variant, 'count' => $count]), 201)['pieces'], 'id');
    }

    /**
     * Un boîtier créé, inscrit, qui a annoncé ses appareils.
     *
     * @param list<array<string, mixed>> $devices
     *
     * @return array{0: int, 1: string, 2: array<string, int>} identifiant, jeton, identifiants des appareils par numéro de série
     */
    private function enrolled(string $name, array $devices, string $machine = self::MACHINE): array
    {
        $created = $this->json($this->admin('POST', self::BOXES, ['name' => "$name $this->s"]), 201);
        $enrolment = $this->json($this->box('POST', '/inscription', ['key' => $created['key'], 'machine' => $machine, 'version' => '1.0.0', 'arch' => 'amd64'], null));
        $token = $enrolment['token'];
        $this->machines[$token] = $machine;
        $this->answered[$token] = $enrolment['serverTime'];
        $answer = $this->json($this->box('POST', '/signe-de-vie', ['now' => gmdate('Y-m-d\TH:i:s\Z'), 'machine' => $machine, 'devices' => $devices, 'lastServerTime' => $this->answered[$token]], $token));
        $this->answered[$token] = $answer['serverTime'];

        return [$created['box']['id'], $token, array_column($answer['settings']['devices'], 'id', 'serial')];
    }

    /**
     * Un résultat d'imprimante envoyé par un boîtier.
     *
     * @param array<string, mixed> $data
     *
     * @return array{0: string, 1: ?string} état et code de la réponse du fait
     */
    private function fact(string $token, string $type, string $serial, array $data, ?string $key = null): array
    {
        $fact = ['key' => $key ?? 'fait-' . $this->s . '-' . str_pad((string) ++$this->n, 4, '0', STR_PAD_LEFT), 'type' => $type, 'at' => gmdate('Y-m-d\TH:i:s\Z'), 'device' => $serial, 'data' => $data];
        $result = $this->json($this->box('POST', '/faits', ['facts' => [$fact]], $token))['results'][0];

        return [$result['status'], $result['code']];
    }

    /** Un appel du boîtier : son jeton dans l'en-tête X-Loss-Box */
    private function box(string $method, string $path, ?array $body, ?string $token): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . '/api/pertes/boitier' . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_LOSS_BOX' => $token,
        ]) + ['REMOTE_ADDR' => $this->ip], $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }
}
