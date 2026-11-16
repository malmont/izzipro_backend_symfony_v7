<?php

namespace App\Tests\Functional\LossPrevention;

use App\LossPrevention\Services\LossRuleCatalog;
use App\Services\CaisseService\StoreClock;

/**
 * Lot L3-b2b (16/11/2026, `docs/prevention-pertes.md` § 16.19) : la règle « articles suivis vendus sans lecture ». Par POSTE de
 * caisse et par JOURNÉE du magasin, jamais par personne : le constat ne liste personne, ses preuves ne portent que des nombres.
 * Elle lit les articles suivis vendus (`loss_sale_unit`, lot L3-b2a) à chaque clôture d'un poste.
 */
class LossPreventionUnreadSoldTest extends LossPreventionTestCase
{
    private const RULE = self::BASE . '/regles/' . LossRuleCatalog::UNREAD_SOLD;

    private string $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = strtoupper(bin2hex(random_bytes(3)));
        $this->flags(true, true);
        $this->db->executeStatement('UPDATE loss_setting SET catch_up_seconds = NULL');
    }

    public function testTooManyTrackedItemsSoldWithoutAReadOnATillInADayOpenAFindingThatNamesNobody(): void
    {
        $clock = static::getContainer()->get(StoreClock::class);
        $today = $clock->day(new \DateTimeImmutable());
        if ($clock->day(new \DateTimeImmutable('-40 minutes')) !== $today) {
            $this->markTestSkipped('dans les 40 minutes qui suivent minuit au magasin, les ventes « d\'il y a 40 minutes » de ce scénario sont de la veille');
        }
        // La règle est au catalogue, avec ses deux seuils ; elle n'a pas de variante par personne
        $rule = array_column($this->json($this->admin('GET', self::BASE . '/regles'))['rules'], null, 'code')['UNREAD_SOLD'];
        $this->assertSame(['Articles suivis vendus sans lecture', 3, true, ['minCount' => 5, 'minShare' => 20], null],
            [$rule['label'], $rule['wave'], $rule['enabled'], array_column($rule['params'], 'value', 'key'), $rule['personVariant']]);
        $this->assertSame([[1, 500], [1, 100]], array_map(fn (array $p): array => [$p['min'], $p['max']], $rule['params']));
        $set = fn (array $body, int $status = 200): array => $this->json($this->admin('PUT', self::RULE, $body), $status);
        $this->assertSame(['params.minCount' => 'PARAM_OUT_OF_RANGE', 'params.minShare' => 'PARAM_OUT_OF_RANGE'], $set(['params' => ['minCount' => 0, 'minShare' => 101]], 422)['fields']);
        $set(['params' => ['minCount' => 3, 'minShare' => 50]]);

        // Un poste de caisse à lui : la règle compte par poste et par jour, et d'autres essais de la suite vendent des articles suivis
        // sur le poste commun le même jour
        $common = $this->register;
        $this->register = (int) $this->json($this->admin('POST', '/api/cash-registers', ['name' => "Caisse sans lecture $this->s"]), 201)['id'];
        [$v] = $this->article('Sac');
        // deux pièces à puce qui existent depuis dix jours, jamais lues (aucun tapis ici)
        [$first, $second] = [$this->taggedSince($v, '01', 14400), $this->taggedSince($v, '02', 14400)];
        $this->loginPos(['ROLE_USER_POS']);
        $this->openSession();
        $this->drain();
        // cinq ventes de cet article il y a 40 minutes, puis une à l'instant
        $old = [];
        for ($i = 0; $i < 5; ++$i) {
            $old[] = $this->sold($v);
        }
        $this->db->executeStatement("UPDATE \"order\" SET order_date = order_date - INTERVAL '40 minutes' WHERE id IN (?, ?, ?, ?, ?)", $old);
        $this->drain();
        // la première a bien été lue sur un tapis (reliée, comme l'analyse l'aurait fait)
        $this->db->executeStatement("UPDATE loss_sale_unit SET piece_id = ?, matched_by = 'counter', matched_at = NOW() WHERE order_id = ?", [$this->piece($first), $old[0]]);
        $fresh = $this->sold($v);
        $this->drain();
        $stored = fn (): array => array_map(fn (array $r): array => ['id' => (int) $r['id'], 'day' => (string) $r['store_day'], 'status' => (string) $r['status'], 'facts' => json_decode((string) $r['facts'], true),
            'evidence' => (int) $r['evidence']], $this->db->fetchAllAssociative('SELECT f.id, f.store_day, f.status, f.facts, (SELECT COUNT(*) FROM loss_evidence e WHERE e.finding_id = f.id) AS evidence FROM loss_finding f'
            . " WHERE f.rule_code = 'UNREAD_SOLD' AND f.cash_register_id = ? AND NOT f.exercise ORDER BY f.store_day", [$this->register]));
        $this->assertSame([], $stored(), 'rien tant que le poste n\'est pas fermé');
        // Ce qui ne doit PAS compter pour ce poste et ce jour : les articles d'un autre poste, ceux d'aucun poste, ceux d'il y a neuf jours
        $item = (int) $this->db->fetchOne('SELECT order_item_id FROM loss_sale_unit WHERE order_id = ?', [$old[0]]);
        $unit = fn (int $no, ?int $register, string $ago): int => (int) $this->db->executeStatement('INSERT INTO loss_sale_unit (order_id, order_item_id, unit_no, variant_id, cash_register_id, sold_at, audit_sequence, created_at)'
            . " VALUES (?, ?, ?, ?, ?, NOW() AT TIME ZONE 'UTC' - CAST(? AS interval), 1, NOW())", [$old[0], $item, $no, $v, $register, $ago]);
        // (vendus un peu APRÈS les ventes du poste : la sortie est commune à tous les postes, la lecture de sortie qui suit va à la vente
        // la plus ancienne qui attend, et ce doit être une vente de ce poste)
        foreach ([[90, $common, '39 minutes'], [91, $common, '38 minutes'], [92, $common, '37 minutes'], [93, null, '36 minutes'], [94, $this->register, '9 days']] as [$no, $register, $ago]) {
            $unit($no, $register, $ago);
        }
        // … et ce qui compte pour une AUTRE journée de ce poste : trois articles sans lecture il y a deux jours (jamais jugés : ce
        // poste n'a pas été clos depuis)
        foreach ([95, 96, 97] as $no) {
            $unit($no, $this->register, '2 days');
        }
        $earlier = $clock->day(new \DateTimeImmutable('-2 days'));
        // Une pièce de l'article est lue à la sortie cinq minutes après ces ventes ; l'analyse n'a pas tourné depuis
        $this->db->executeStatement("INSERT INTO loss_piece_event (type, occurred_at, piece_id, tag_id, source, exercise, data) VALUES ('ZONE_SEEN', NOW() AT TIME ZONE 'UTC' - INTERVAL '35 minutes', ?,"
            . " (SELECT id FROM loss_tag WHERE piece_id = ?), 'app', false, '{\"zone\":\"exit\"}')", [$this->piece($second), $this->piece($second)]);

        // Clôture du poste. Le rattrapage passe AVANT la règle : la lecture de sortie est reliée à la plus ancienne vente sans pièce.
        // Restent trois articles sans lecture sur six suivis (le sixième, vendu à l'instant, peut encore être rattrapé : il compte
        // parmi les suivis, pas parmi les « sans lecture »)
        $this->closeWith(0);
        $this->drain();
        $found = $stored();
        $this->assertSame([$earlier, $today], array_column($found, 'day'), 'un constat par poste et par jour : celui d\'aujourd\'hui, et la dernière journée d\'avant où ce poste a vendu');
        $this->assertSame([['tracked' => 3, 'unread' => 3, 'counter' => 0, 'catchUp' => 0, 'returned' => 0, 'share' => 100, 'minCount' => 3, 'minShare' => 50, 'below' => false], 1], [$found[0]['facts'], $found[0]['evidence']]);
        $this->assertSame([['tracked' => 6, 'unread' => 3, 'counter' => 1, 'catchUp' => 1, 'returned' => 0, 'share' => 50, 'minCount' => 3, 'minShare' => 50, 'below' => false], 1], [$found[1]['facts'], $found[1]['evidence']]);
        $id = $found[1]['id'];
        $listed = array_column($this->json($this->admin('GET', self::BASE . '/constats?rule=UNREAD_SOLD&sort=date&page=1&limit=50')), null, 'id')[$id];
        $this->assertSame(['UNREAD_SOLD', false, 'open', null, $this->register, 'Articles suivis vendus sans lecture ce jour-là à ce poste : 3 sur 6 (50 %).'],
            [$listed['rule']['code'], $listed['personPattern'], $listed['status'], $listed['amountAtStake'], $listed['cashRegister']['id'], $listed['summary']]);
        $detail = fn (): array => $this->json($this->admin('GET', self::BASE . '/constats/' . $id));
        $first = $detail();
        $this->assertSame([['type' => 'cash_register', 'id' => $this->register, 'name' => "Caisse sans lecture $this->s", 'label' => 'Poste de caisse'], [], null, null, 1, 'sale_units', 'closing', true],
            [$first['subject'], $first['onDuty'], $first['onDutyNotice'], $first['sessionId'], count($first['evidence']), $first['evidence'][0]['kind'], $first['evidence'][0]['ref']['type'], $first['evidenceIntact']]);
        $lines = array_column($first['evidence'][0]['lines'], 'value', 'label');
        $this->assertSame(['Journée', 'Articles suivis vendus', 'Vendus sans lecture', 'Lus sur le tapis du comptoir', 'Rattrapés à la sortie', 'Revenus (retour ou vente annulée)'], array_keys($lines));
        $this->assertSame(['6', '3', '1', '1', '0'], array_slice(array_values($lines), 1));
        // jamais par personne : ni le constat, ni sa preuve, ni ce que la base garde ne désignent quelqu'un
        $row = $this->db->fetchAssociative('SELECT dedup_key, subject_type, subject_id, on_duty, amount_at_stake, session_id FROM loss_finding WHERE id = ?', [$id]);
        $this->assertSame(["UNREAD_SOLD:register:$this->register:day:$today", 'cash_register', $this->register, [], null, null],
            [$row['dedup_key'], $row['subject_type'], (int) $row['subject_id'], json_decode((string) $row['on_duty'], true), $row['amount_at_stake'], $row['session_id']]);
        $snapshot = (string) $this->db->fetchOne('SELECT snapshot FROM loss_evidence WHERE finding_id = ?', [$id]);
        $this->assertSame(['cashRegisterId', 'catchUp', 'counter', 'day', 'returned', 'tracked', 'unread'], array_keys(json_decode($snapshot, true)), 'la copie figée : des nombres, la journée et le poste, rien d\'autre');
        foreach ([$this->mark, 'userId', 'sellerId', 'closedById', 'z_report', '@'] as $personal) {
            $this->assertStringNotContainsString($personal, json_encode($first['evidence']) . json_encode($first['subject']) . $snapshot . json_encode($found[1]['facts']), "aucune personne dans le constat ($personal)");
        }

        // Le gérant décide. Une clôture de plus qui trouve les MÊMES nombres n'ajoute rien : ni preuve, ni « du nouveau depuis la décision »
        $this->json($this->admin('PUT', self::BASE . '/constats/' . $id . '/decision', ['status' => 'watch']));
        sleep(1); // dates à la seconde : « du nouveau depuis la décision » se lit par une preuve datée APRÈS elle (comme dans LossPreventionApiTest)
        $close = function (): array {
            $this->openSession();
            $this->closeWith(0);
            $this->drain();

            return array_column($this->db->fetchAllAssociative("SELECT f.id, f.status, f.facts, (SELECT COUNT(*) FROM loss_evidence e WHERE e.finding_id = f.id) AS evidence FROM loss_finding f WHERE f.rule_code = 'UNREAD_SOLD'"), null, 'id');
        };
        $same = $close();
        $this->assertSame([1, 1, false], [(int) $same[$id]['evidence'], (int) $same[$found[0]['id']]['evidence'], $detail()['newEvidenceSinceDecision']]);
        // Une vente annulée entre-temps : l'article revenu n'est plus compté. Les nombres ont changé, le constat est mis à jour même
        // s'il est passé SOUS les seuils (il ne reste pas à afficher un chiffre démenti), et sa décision n'est pas touchée
        $this->openSession();
        $this->json($this->pos('POST', '/api/order/cancel/' . $old[2], ['paymentMethod' => 2, 'cashRegisterId' => $this->register]));
        $this->closeWith(0);
        $this->drain();
        $changed = $detail();
        $facts = fn (): array => json_decode((string) $this->db->fetchOne('SELECT facts FROM loss_finding WHERE id = ?', [$id]), true);
        $this->assertSame([2, 'watch', true, 'Articles suivis vendus sans lecture ce jour-là à ce poste : 2 sur 5 (40 %), sous les seuils depuis la dernière clôture.'],
            [count($changed['evidence']), $changed['status'], $changed['newEvidenceSinceDecision'], $changed['summary']]);
        $this->assertSame(['tracked' => 5, 'unread' => 2, 'counter' => 1, 'catchUp' => 1, 'returned' => 1, 'share' => 40, 'minCount' => 3, 'minShare' => 50, 'below' => true], $facts());
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_evidence WHERE finding_id = ?', [$found[0]['id']]), 'la journée d\'avant n\'a pas bougé : rien de plus');
        // Un seuil changé ne touche pas un constat dont les ventes n'ont pas bougé : ni preuve de plus, ni nouveau jugement
        $set(['params' => ['minCount' => 2, 'minShare' => 40]]);
        $this->assertSame([2, true, 3], [(int) $close()[$id]['evidence'], $facts()['below'], $facts()['minCount']]);
        // La règle éteinte ne dit plus rien, même quand les nombres changent (une autre vente annulée)
        $set(['enabled' => false]);
        $this->openSession();
        $this->json($this->pos('POST', '/api/order/cancel/' . $old[3], ['paymentMethod' => 2, 'cashRegisterId' => $this->register]));
        $this->closeWith(0);
        $this->drain();
        $this->assertSame([2, 1], [(int) $this->db->fetchOne('SELECT COUNT(*) FROM loss_evidence WHERE finding_id = ?', [$id]), $facts()['returned']], 'règle éteinte');
        // Rallumée, elle reprend : les nombres ont changé, la journée est jugée avec les seuils du moment (un article, 25 %)
        $set(['enabled' => true, 'params' => ['minCount' => 1, 'minShare' => 25]]);
        $this->assertSame(3, (int) $close()[$id]['evidence']);
        $this->assertSame(['tracked' => 4, 'unread' => 1, 'counter' => 1, 'catchUp' => 1, 'returned' => 2, 'share' => 25, 'minCount' => 1, 'minShare' => 25, 'below' => false], $facts());
        $this->assertSame('Articles suivis vendus sans lecture ce jour-là à ce poste : 1 sur 4 (25 %).', $detail()['summary']);
        $this->assertCount(2, $stored(), 'toujours un constat par poste et par jour : ni l\'autre poste, ni les articles sans poste, ni ceux d\'il y a neuf jours n\'en ont ouvert');

        // L'aperçu d'un réglage joue la vraie règle sans rien écrire : ni constat, ni preuve
        $written = fn (): array => array_map('intval', $this->db->fetchNumeric('SELECT (SELECT COUNT(*) FROM loss_finding), (SELECT COUNT(*) FROM loss_evidence)'));
        $before = $written();
        $none = $this->json($this->admin('POST', self::BASE . '/regles/apercu', ['code' => 'UNREAD_SOLD', 'params' => ['minCount' => 500, 'minShare' => 100]]));
        $some = $this->json($this->admin('POST', self::BASE . '/regles/apercu', ['code' => 'UNREAD_SOLD', 'params' => ['minCount' => 1, 'minShare' => 1]]));
        $this->assertSame(['UNREAD_SOLD', 0, 0], [$none['code'], $none['wouldOpen'], $none['skipped']]);
        $this->assertGreaterThanOrEqual(1, $some['wouldOpen']);
        $this->assertSame($before, $written());
    }

    public function testTheCatchUpRunsBeforeAClosingOnlyWhenSomethingChangedAndTheRuleIsOn(): void
    {
        // Le rattrapage coûte des lectures : avant une clôture, il ne passe que s'il y a du neuf depuis le dernier rattrapage du passage
        // (début du passage, vente ou retour lu depuis) et si la règle qui en dépend est allumée
        [$v] = $this->article('Sac');
        $this->loginPos(['ROLE_USER_POS']);
        $this->drain();
        $sales = new class($this->tenant()) extends \App\LossPrevention\Services\LossSaleLinkService {
            public int $calls = 0;

            public function catchUp(int $now): int
            {
                ++$this->calls;

                return parent::catchUp($now);
            }
        };
        $analysis = $this->analysisWith([], $sales);
        $closing = function (?int $variant = null): void {
            $this->openSession();
            if ($variant !== null) {
                $this->sold($variant);
            }
            $this->closeWith(0);
        };
        $calls = function () use ($analysis, $sales): int {
            $sales->calls = 0;
            $this->assertFalse($analysis->run()['pending']);

            return $sales->calls;
        };
        // trois clôtures à la suite, sans vente entre elles : un seul rattrapage, avant la première ; rien de plus en fin de passage
        $closing();
        $closing();
        $closing();
        $this->assertSame(1, $calls());
        // une vente entre deux clôtures : un rattrapage de plus avant la clôture qui la suit, et pas avant la dernière
        $closing();
        $closing($v);
        $closing();
        $this->assertSame(2, $calls());
        // une vente après la dernière clôture : le rattrapage de fin de passage
        $closing();
        $this->openSession();
        $this->sold($v);
        $this->assertSame(2, $calls());
        $this->closeWith(0);
        // règle éteinte : aucun rattrapage avant une clôture, celui de fin de passage seulement
        $this->json($this->admin('PUT', self::RULE, ['enabled' => false]));
        $closing();
        $closing($v);
        $this->assertSame(1, $calls());
    }

    public function testThePiecesScreenKnowsSoldAndReturnedPieces(): void
    {
        // Trois pièces d'un article : une vendue, une revenue (états que l'analyse pose en reliant les ventes), une en rayon
        [$v] = $this->article('Gant');
        $created = $this->json($this->admin('POST', self::BASE . '/pieces', ['variantId' => $v, 'count' => 3]), 201);
        $ids = array_column($created['pieces'], 'id');
        sort($ids);
        $this->db->executeStatement("UPDATE loss_piece SET status = 'sold' WHERE id = ?", [$ids[0]]);
        $this->db->executeStatement("UPDATE loss_piece SET status = 'returned' WHERE id = ?", [$ids[1]]);
        $list = fn (string $status): array => array_column($this->json($this->admin('GET', self::BASE . "/pieces?variantId=$v&status=$status")), 'statusLabel', 'id');
        $this->assertSame([[$ids[0] => 'Vendue'], [$ids[1] => 'Retournée'], [$ids[2] => 'En service'], []], [$list('sold'), $list('returned'), $list('created'), $list('retired')]);
        $this->assertSame(['status' => 'INVALID_VALUE'], $this->json($this->admin('GET', self::BASE . "/pieces?variantId=$v&status=missing"), 422)['fields']);
        // La synthèse : une pièce vendue n'est plus « en service » (elle n'est plus dans la boutique), une pièce revenue l'est de nouveau
        $synthesis = $this->json($this->admin('GET', self::BASE . "/pieces/synthese?variantId=$v"));
        $this->assertSame([['active' => 2, 'sold' => 1, 'retired' => 0, 'tagged' => 0, 'untagged' => 2], 2, 1, 0], [$synthesis['rows'][0]['pieces'], $synthesis['totals']['pieces'], $synthesis['totals']['sold'], $synthesis['totals']['retired']]);
        // une pièce vendue qui porte une puce ne compte pas parmi les pièces à puce en service
        foreach ([$ids[0], $ids[2]] as $n => $piece) {
            $this->db->executeStatement("INSERT INTO loss_tag (identifier, raw, technology, status, piece_id, exercise, associated_at, created_at, updated_at) VALUES (?, ?, 'nfc', 'associated', ?, false, NOW(), NOW(), NOW())",
                ['04' . $this->s . 'DD000' . $n, '04' . $this->s . 'DD000' . $n, $piece]);
        }
        $this->assertSame(['active' => 2, 'sold' => 1, 'retired' => 0, 'tagged' => 1, 'untagged' => 1], $this->json($this->admin('GET', self::BASE . "/pieces/synthese?variantId=$v"))['rows'][0]['pieces']);
    }

    // ----- aides -----

    /** Une vente au comptoir d'un article, payée en espèces ; rend l'identifiant de la commande */
    private function sold(int $variant): int
    {
        $body = ['items' => [['productVariantId' => $variant, 'quantity' => 1]], 'cashRegisterId' => $this->register];
        $quote = $this->json($this->pos('POST', '/api/pos/quote', $body));

        return (int) $this->json($this->pos('POST', '/api/order/create-multi-payment', $body + ['paymentMethods' => [['type' => 2, 'amount' => $quote['cashTotal'] / 100]]]), 201)['orderId'];
    }

    private function piece(string $identifier): int
    {
        return (int) $this->db->fetchOne('SELECT piece_id FROM loss_tag WHERE identifier = ?', [$identifier]);
    }

    /** Une vraie pièce et sa puce NFC qui existent depuis `$minutes` minutes (écrites telles que le module les aurait créées alors) */
    private function taggedSince(int $variant, string $suffix, int $minutes): string
    {
        $nfc = '04' . $this->s . 'CC00' . $suffix;
        $at = gmdate('Y-m-d H:i:s', time() - $minutes * 60);
        $piece = (int) $this->db->fetchOne("INSERT INTO loss_piece (number, variant_id, product_id, status, exercise, created_at, updated_at) VALUES (?, ?, (SELECT product_id FROM product_variant WHERE id = ?), 'created', false, ?, ?) RETURNING id",
            [900000000 + random_int(1, 99999999), $variant, $variant, $at, $at]);
        $this->db->executeStatement("INSERT INTO loss_tag (identifier, raw, technology, status, piece_id, exercise, associated_at, created_at, updated_at) VALUES (?, ?, 'nfc', 'associated', ?, false, ?, ?, ?)", [$nfc, $nfc, $piece, $at, $at, $at]);

        return $nfc;
    }

    /** @return list<int> les variantes d'un article créé pour ce test (une par défaut) */
    private function article(string $name, int $variants = 1): array
    {
        $slug = 'l3b2b-' . bin2hex(random_bytes(4));
        $this->db->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, is_web, is_pos) VALUES (nextval('product_id_seq'), :n, 3000, false, 0, NOW(), :s, 'standard', false, true)",
            ['n' => "$name L3B2B-$this->s", 's' => $slug]);
        $product = (int) $this->db->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]);
        $ids = [];
        for ($i = 0; $i < $variants; ++$i) {
            $ids[] = (int) $this->db->fetchOne("INSERT INTO product_variant (id, product_id, stock_quantity) VALUES (nextval('product_variant_id_seq'), ?, 50) RETURNING id", [$product]);
        }

        return $ids;
    }
}
