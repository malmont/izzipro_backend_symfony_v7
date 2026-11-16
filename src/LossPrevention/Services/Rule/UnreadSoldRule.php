<?php

namespace App\LossPrevention\Services\Rule;

use App\LossPrevention\Entity\LossEvidence;
use App\LossPrevention\Entity\LossFinding;
use App\LossPrevention\Services\LossAnalysisContext;
use App\LossPrevention\Services\LossAuditEntry;
use App\LossPrevention\Services\LossRuleCatalog;
use App\LossPrevention\Services\LossSaleLinkService;

/**
 * « Articles suivis vendus sans lecture » (lot L3-b2b, `docs/prevention-pertes.md` § 16.19) : sur un POSTE de caisse et une JOURNÉE
 * du magasin, trop d'articles suivis ont été vendus sans que leur pièce soit lue (ni sur le tapis du poste, ni rattrapée à la
 * sortie). Tapis en panne, tapis non utilisé, puces retirées avant la caisse : le constat ne dit pas pourquoi, il dit où et quand.
 *
 * - **Par poste et par jour, jamais par personne** : le constat ne liste personne « en poste », ses preuves ne portent que des
 *   nombres. Son sujet est le poste de caisse.
 * - Évaluée à chaque clôture d'un poste (rapport Z), APRÈS le rattrapage du passage, pour le jour de la clôture ET pour la dernière
 *   journée d'avant où ce poste a vendu des articles suivis (la veille, le plus souvent) : celle-ci est rejugée une fois ses
 *   rattrapages terminés.
 * - Un article n'est compté « sans lecture » que si sa fenêtre de rattrapage est passée : celui qui peut encore être relié ne
 *   l'est pas. Un article revenu (retour, vente annulée) n'est plus compté du tout.
 * - Deux seuils, tous deux exigés : un nombre d'articles, et leur part parmi les articles suivis vendus ce jour-là sur ce poste.
 * - Un constat par poste et par jour. Une clôture qui trouve d'autres nombres y ajoute sa preuve et les met à jour, même s'ils sont
 *   passés sous les seuils (le constat le dit alors : `below`) ; une clôture qui trouve les mêmes nombres n'ajoute rien.
 *
 * Elle lit `loss_sale_unit` (lot L3-b2a), jamais une table de la caisse.
 */
class UnreadSoldRule implements LossRuleInterface
{
    /** La journée rejugée avec celle de la clôture est la dernière où ce poste a vendu des articles suivis, dans cette limite (jours) */
    public const LOOK_BACK_DAYS = 7;

    public function code(): string
    {
        return LossRuleCatalog::UNREAD_SOLD;
    }

    public function types(): array
    {
        return ['Z_REPORT_CREATED'];
    }

    public function handle(array $entry, LossAnalysisContext $ctx): void
    {
        $register = $entry['cashRegisterId'];
        $reportId = ($entry['subject']['type'] ?? null) === 'z_report' ? $entry['subject']['id'] : LossAuditEntry::intOrNull($entry['data']['reportId'] ?? null);
        if ($register === null || $reportId === null) {
            return;
        }
        $params = $ctx->rules[$this->code()]['params'];
        $closing = $entry['occurredAt'];
        $today = $ctx->clock->day($closing);
        [$todayStart] = $ctx->clock->range($today, $today);
        // La dernière journée d'AVANT aujourd'hui où ce poste a vendu des articles suivis (la veille le plus souvent ; le samedi, pour
        // une boutique fermée le dimanche) est rejugée : ses rattrapages sont terminés. Bornée à une semaine
        $before = $ctx->conn->fetchOne('SELECT MAX(sold_at) FROM loss_sale_unit WHERE cash_register_id = ? AND sold_at < ? AND sold_at >= ?',
            [$register, $todayStart->format('Y-m-d H:i:s'), $todayStart->modify('-' . self::LOOK_BACK_DAYS . ' days')->format('Y-m-d H:i:s')]);
        $days = [$today];
        if (is_string($before) && $before !== '') {
            array_unshift($days, $ctx->clock->day(new \DateTimeImmutable($before, new \DateTimeZone('UTC'))));
        }
        // un article qui peut encore être rattrapé à la sortie n'est pas « sans lecture » : la fenêtre du site, sinon celle du code
        $window = (int) ($ctx->conn->fetchOne('SELECT catch_up_seconds FROM loss_setting ORDER BY id LIMIT 1') ?: LossSaleLinkService::CATCH_UP_WINDOW);
        $settled = $closing->modify('-' . $window . ' seconds')->format('Y-m-d H:i:s');
        foreach ($days as $day) {
            [$start, $end] = $ctx->clock->range($day, $day);
            $row = $ctx->conn->fetchAssociative(
                'SELECT COUNT(*) FILTER (WHERE returned_at IS NULL) AS tracked,'
                . ' COUNT(*) FILTER (WHERE returned_at IS NULL AND piece_id IS NULL AND sold_at <= :settled) AS unread,'
                . " COUNT(*) FILTER (WHERE returned_at IS NULL AND matched_by = 'counter') AS counter,"
                . " COUNT(*) FILTER (WHERE returned_at IS NULL AND matched_by = 'catch_up') AS catch_up,"
                . ' COUNT(*) FILTER (WHERE returned_at IS NOT NULL) AS returned, MIN(sold_at) AS first_sale, MAX(sold_at) AS last_sale'
                . ' FROM loss_sale_unit WHERE cash_register_id = :register AND sold_at >= :start AND sold_at < :end',
                ['settled' => $settled, 'register' => $register, 'start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s')]);
            [$tracked, $unread] = [(int) $row['tracked'], (int) $row['unread']];
            $numbers = ['tracked' => $tracked, 'unread' => $unread, 'counter' => (int) $row['counter'], 'catchUp' => (int) $row['catch_up'], 'returned' => (int) $row['returned']];
            $over = $unread >= $params['minCount'] && $unread * 100 >= $params['minShare'] * $tracked;
            $key = $this->code() . ':register:' . $register . ':day:' . $day;
            // Le constat de ce poste et de ce jour existe-t-il déjà ? S'il dit déjà ces nombres, il n'y a rien à ajouter : une clôture de
            // plus n'est pas « du nouveau », et un seuil changé depuis ne touche pas un constat dont les ventes n'ont pas bougé. Si les
            // nombres ont changé, il est mis à jour même sous les seuils : il ne reste pas à afficher un chiffre que le rattrapage ou
            // un retour a démenti. L'aperçu d'un réglage ne connaît aucun constat : il ne compte que ce qui ouvre
            $known = $ctx->writer->factsOf($key);
            if ($known !== null) {
                if (array_intersect_key($known, $numbers) == $numbers) {
                    continue; // mêmes nombres : rien de neuf
                }
            } elseif (!$over) {
                continue;
            }
            $ctx->writer->record([
                'rule_code' => $this->code(), 'variant' => LossFinding::VARIANT_RULE, 'dedup_key' => $key,
                'store_day' => $day, 'occurred_from' => (string) ($row['first_sale'] ?? $start->format('Y-m-d H:i:s')), 'occurred_to' => (string) ($row['last_sale'] ?? $start->format('Y-m-d H:i:s')),
                'cash_register_id' => $register, 'session_id' => null, 'amount_at_stake' => null, 'currency' => $ctx->currency,
                'subject_type' => 'cash_register', 'subject_id' => $register,
                'facts' => $numbers + ['share' => $tracked > 0 ? intdiv($unread * 100, $tracked) : 0, 'minCount' => (int) $params['minCount'], 'minShare' => (int) $params['minShare'], 'below' => !$over],
                'on_duty' => [], // jamais par personne : personne n'est listé
                'backfill' => $ctx->isBackfill($entry['sequence']),
            ], [[
                // une preuve par clôture qui change les nombres : les nombres du moment, et rien d'autre (aucune vente, aucun compte). Son
                // renvoi dit quelle clôture a fait juger la journée (numéro du rapport, entrée du journal de caisse) : un repère de
                // vérification sans écran, qui ne sert ni nom ni compte
                'kind' => LossEvidence::SALE_UNITS, 'at' => $closing, 'refType' => 'closing', 'refId' => $reportId, 'sequence' => $entry['sequence'], 'hash' => $entry['hash'],
                'snapshot' => ['day' => $day, 'cashRegisterId' => $register] + $numbers,
            ]]);
        }
    }
}
