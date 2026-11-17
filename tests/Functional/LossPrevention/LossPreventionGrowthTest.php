<?php

namespace App\Tests\Functional\LossPrevention;

use App\LossPrevention\Services\LossRuleCatalog;

/**
 * Lot L5-b (17/11/2026, `docs/prevention-pertes.md` § 18.4, réserve 1, et § 18.9) : un constat DÉCIDÉ « à suivre », « expliqué » ou « classé
 * sans suite » dont le nombre suivi a au moins doublé depuis la décision, avec au moins 3 de plus, redevient « nouveau », une fois par
 * décision ; sa décision et sa note sont gardées. Jamais un constat confirmé.
 */
class LossPreventionGrowthTest extends LossPreventionTestCase
{
    public function testADecidedFindingThatDoublesBecomesNewOnceAndKeepsItsDecision(): void
    {
        $this->drain();
        $this->loginPos(['ROLE_USER_POS']);
        $session = $this->openSession();
        $open = function (int $times, string $reason) {
            foreach (range(1, $times) as $i) {
                $this->pos('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => "$reason $i"]);
            }

            return $this->json($this->admin('POST', self::BASE . '/analyse'));
        };
        $this->assertSame(1, $open(4, 'Monnaie')['opened']);
        $id = (int) $this->db->fetchOne('SELECT id FROM loss_finding WHERE dedup_key = ?', ['DRAWER_NO_SALE:session:' . $session]);
        $row = fn (): array => $this->db->fetchAssociative('SELECT status, decision_note, decided_count, grew_at, seen_at FROM loss_finding WHERE id = ?', [$id]);
        $grewLines = fn (): array => array_map(fn ($d) => json_decode((string) $d, true), $this->db->fetchFirstColumn("SELECT data FROM loss_journal WHERE type = 'FINDING_GREW' AND finding_id = ? ORDER BY id", [$id]));
        $isNew = fn (): bool => in_array($id, array_column($this->json($this->admin('GET', self::BASE . '/constats?isNew=true&rule=DRAWER_NO_SALE&limit=100')), 'id'), true);

        // Décidé « expliqué » à 4 : le nombre suivi est gardé
        $this->json($this->admin('PUT', self::BASE . "/constats/$id/decision", ['status' => 'explained', 'note' => 'Monnaie pour le marché']));
        $this->assertSame(['explained', 4, null], [$row()['status'], (int) $row()['decided_count'], $row()['grew_at']]);
        $this->assertNull($this->json($this->admin('GET', self::BASE . "/constats/$id"))['grewSinceDecision']);

        // 7 : pas encore doublé ; « du nouveau depuis la décision » seulement
        sleep(1);
        $this->assertSame(3, $open(3, 'Encore')['completed'], 'une complétion par ouverture');
        $this->assertSame([null, []], [$row()['grew_at'], $grewLines()]);
        $this->assertFalse($isNew());
        // 8 : doublé, 4 de plus. Il redevient « nouveau », garde sa décision et sa note
        $this->assertSame(1, $open(1, 'Doublé')['completed']);
        $this->assertNotNull($row()['grew_at']);
        $this->assertSame(['explained', 'Monnaie pour le marché', 4, null], [$row()['status'], $row()['decision_note'], (int) $row()['decided_count'], $row()['seen_at']]);
        $this->assertSame([['before' => 4, 'after' => 8]], $grewLines());
        $this->assertTrue($isNew(), 'il est de nouveau dans les constats nouveaux');
        $line = array_values(array_filter($this->json($this->admin('GET', self::BASE . '/constats?rule=DRAWER_NO_SALE&limit=100')), fn ($r) => $r['id'] === $id))[0];
        $this->assertSame([true, 4, 8], [$line['isNew'], $line['grewSinceDecision']['before'], $line['grewSinceDecision']['after']]);
        $detail = $this->json($this->admin('GET', self::BASE . "/constats/$id?locale=fr"));
        $this->assertSame(['at', 'before', 'after'], array_keys($detail['grewSinceDecision']));
        $this->assertSame(['explained', 'Monnaie pour le marché'], [$detail['decision']['status'], $detail['decision']['note']]);
        $last = end($detail['history']);
        $this->assertSame(['grew', null, 4, 8], [$last['kind'], $last['by'], $last['before'], $last['after']], 'écrit par l\'analyse : sans personne');
        $this->assertSame('A grossi depuis votre décision : 4 à la décision, 8 maintenant.', $last['statusLabel']);
        $this->assertSame('decision', $detail['history'][0]['kind']);
        $this->assertFalse($isNew(), 'consulté : il n\'est plus nouveau');

        // Une fois par décision : 9 ne le signale pas de nouveau
        $this->assertSame(1, $open(1, 'Neuf')['completed']);
        $this->assertCount(1, $grewLines());
        $this->assertSame(9, $this->json($this->admin('GET', self::BASE . "/constats/$id"))['grewSinceDecision']['after']);
        // La même décision redite : la référence repart du nombre du moment, « a grossi » s'efface
        $this->json($this->admin('PUT', self::BASE . "/constats/$id/decision", ['status' => 'explained', 'note' => 'Monnaie pour le marché']));
        $this->assertSame([9, null], [(int) $row()['decided_count'], $row()['grew_at']]);
        $this->assertNull($this->json($this->admin('GET', self::BASE . "/constats/$id"))['grewSinceDecision']);
        // Confirmé : jamais signalé de nouveau, même doublé
        $this->json($this->admin('PUT', self::BASE . "/constats/$id/decision", ['status' => 'confirmed', 'note' => 'Vu avec l\'équipe']));
        sleep(1);
        $this->assertSame(10, $open(10, 'Confirmé')['completed']);
        $this->assertSame([null, 1], [$row()['grew_at'], count($grewLines())]);
        // Revenu à « nouveau » : plus de référence ; rien à surveiller
        $this->json($this->admin('PUT', self::BASE . "/constats/$id/decision", ['status' => 'open']));
        $this->assertSame([null, null], [$row()['decided_count'], $row()['grew_at']]);
        $this->closeWith(0);
    }

    public function testTheGrowthNeedsThreeMoreAndADecisionTakenBeforeTheLotTakesItsFirstKnownNumber(): void
    {
        $this->drain();
        // Le seuil le plus bas : une ouverture sans vente ouvre le constat (nombre suivi : 1)
        $this->json($this->admin('PUT', self::BASE . '/regles/DRAWER_NO_SALE', ['params' => ['maxPerSession' => 0]]));
        $this->loginPos(['ROLE_USER_POS']);
        $session = $this->openSession();
        $open = function (string $reason) {
            $this->pos('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => $reason]);

            return $this->json($this->admin('POST', self::BASE . '/analyse'));
        };
        $this->assertSame(1, $open('Un')['opened']);
        $id = (int) $this->db->fetchOne('SELECT id FROM loss_finding WHERE dedup_key = ?', ['DRAWER_NO_SALE:session:' . $session]);
        $grew = fn (): bool => $this->db->fetchOne('SELECT grew_at FROM loss_finding WHERE id = ?', [$id]) !== null;
        $this->json($this->admin('PUT', self::BASE . "/constats/$id/decision", ['status' => 'watch']));
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT decided_count FROM loss_finding WHERE id = ?', [$id]));
        // 2, puis 3 : doublé et plus, mais pas 3 de plus
        $open('Deux');
        $open('Trois');
        $this->assertFalse($grew(), 'doublé, mais seulement 2 de plus');
        $open('Quatre');
        $this->assertTrue($grew(), '4 : doublé, et 3 de plus');

        // Une décision prise avant le lot n'a pas de nombre gardé : la référence est le nombre d'avant sa première complétion, alors gardé
        $this->json($this->admin('PUT', self::BASE . "/constats/$id/decision", ['status' => 'dismissed']));
        $this->db->executeStatement('UPDATE loss_finding SET decided_count = NULL WHERE id = ?', [$id]);
        $open('Cinq');
        $this->assertSame([5 - 1, false], [(int) $this->db->fetchOne('SELECT decided_count FROM loss_finding WHERE id = ?', [$id]), $grew()]);
        foreach (['Six', 'Sept', 'Huit'] as $reason) {
            $open($reason);
        }
        $this->assertTrue($grew(), '8 contre 4 : doublé, et 4 de plus');
        $this->assertSame([['before' => 4, 'after' => 8]], array_map(fn ($d) => json_decode((string) $d, true),
            $this->db->fetchFirstColumn("SELECT data FROM loss_journal WHERE type = 'FINDING_GREW' AND finding_id = ? ORDER BY id DESC LIMIT 1", [$id])));
        $this->closeWith(0);

        // Le nombre suivi d'une règle : `count`, `unread` pour « vendus sans lecture » ; aucun pour un événement unique
        $this->assertSame([4, 3, null, null], [LossRuleCatalog::trackedNumber('DRAWER_NO_SALE', ['count' => 4]), LossRuleCatalog::trackedNumber('UNREAD_SOLD', ['count' => 9, 'unread' => 3]),
            LossRuleCatalog::trackedNumber('EXIT_WITHOUT_SALE', ['pieceId' => 7]), LossRuleCatalog::trackedNumber('DRAWER_NO_SALE', ['count' => '4'])]);
    }
}
