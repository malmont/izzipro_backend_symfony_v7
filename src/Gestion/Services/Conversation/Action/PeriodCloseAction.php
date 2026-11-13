<?php

namespace App\Gestion\Services\Conversation\Action;

use App\Entity\Collections;
use App\Entity\Commande;
use App\Entity\PeriodResult;
use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\PeriodCalendar;
use App\Gestion\Services\PeriodResultCalculator;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\CollectionDashboardUseCase\CloseCollectionUseCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * `period.close` : clôturer une période de gestion TERMINÉE (lot IA-ACT-4, 13/11/2026, `docs/conversation-gestion.md` § 12). La clôture
 * est celle de la route existante (`CloseCollectionUseCase`) : elle fige le tableau de bord de la période, fige et ferme chaque commande
 * fournisseur datée dans la période qui était encore ouverte, note la quantité figée de leurs articles et date la clôture ; ensuite toute
 * écriture datée dans la période est refusée.
 *
 * L'aperçu montre le résultat qui sera figé, calculé SANS cache (une vente faite dans la minute change l'empreinte), les commandes qui
 * seront fermées et les alertes. La période en cours n'est pas proposée (`PERIOD_NOT_ENDED`).
 *
 * L'annulation est EXACTE et ne passe pas par la réouverture existante (qui rouvre toutes les commandes et marque la période « rouverte ») :
 * la confirmation note l'état d'avant, l'annulation le remet tel quel. La période retrouve ses dates de clôture et de réouverture, seules
 * les commandes fermées par cette clôture sont rouvertes, la quantité figée de leurs articles revient, les chiffres figés créés par la
 * clôture et le résultat figé depuis sont retirés. Refusée si la période ou une de ces commandes a changé depuis (réception de stock comprise).
 */
class PeriodCloseAction extends AbstractGestionAction
{
    public const AUDIT = 'gestion-periodes-cloture';

    public function __construct(TenantEntityManagerProvider $emProvider, TranslatorInterface $translator, TenantCurrencyProvider $currency,
        private readonly CloseCollectionUseCase $close, private readonly PeriodCalendar $calendar, private readonly PeriodResultCalculator $calculator, private readonly ContentAuditRecorder $audit)
    {
        parent::__construct($emProvider, $translator, $currency);
    }

    public static function key(): string
    {
        return 'period.close';
    }

    public function build(array $answers, string $locale): array
    {
        // Périodes proposées : terminées et encore ouvertes (la précédente pendant ses jours de grâce, une période rouverte), de la plus récente à la plus ancienne
        $options = [];
        foreach ($this->em()->getRepository(Collections::class)->findBy(['kind' => Collections::KIND_PERIOD], ['startDateCollection' => 'DESC'], 36) as $period) {
            if (!$period->isDel() && $this->refusal($period) === null) {
                $options[] = ['value' => (int) $period->getId(), 'label' => PeriodCalendar::displayName($period, $locale), 'from' => $period->getStartDateCollection()?->format('Y-m-d'), 'to' => $period->getEndDateCollection()?->format('Y-m-d')];
            }
        }
        $issues = [];
        if (isset($answers['periodId'])) {
            $period = $this->em()->find(Collections::class, (int) $answers['periodId']);
            $refusal = $period === null || !$period->isPeriod() ? 'PERIOD_NOT_FOUND' : $this->refusal($period);
            if ($refusal !== null) {
                $issues[] = ['path' => 'periodId', 'code' => $refusal];
            }
        }
        $only = count($options) === 1 ? $options[0]['value'] : null; // une seule période à clôturer : elle est proposée

        return ['questions' => [$this->question('periodId', true, ['type' => 'choice'], $options, $answers['periodId'] ?? $only, $locale, [], !isset($answers['periodId']) && $only !== null)], 'issues' => $issues];
    }

    public function accept(array $question, mixed $value, int $index, array $answers): mixed
    {
        if ($value === null) {
            return null;
        }
        $period = is_int($value) ? $this->em()->find(Collections::class, $value) : null;
        if ($period === null || !$period->isPeriod()) {
            $this->fail($index, 'PERIOD_NOT_FOUND');
        }
        $refusal = $this->refusal($period);

        return $refusal === null ? $value : $this->fail($index, $refusal);
    }

    public function preview(array $answers, string $locale): array
    {
        $period = $this->period($answers);
        // Résultat qui sera figé : calculé ici, sans le cache de 60 secondes de la route du résultat (une vente de la dernière minute compte)
        $r = $this->calculator->compute($period, null);
        $commandes = $this->openCommandes($period);
        $withoutShipping = $commandes === [] ? 0 : (int) $this->conn()->fetchOne('SELECT COUNT(*) FROM commande c WHERE c.id IN (?) AND NOT EXISTS (SELECT 1 FROM frais_de_port f WHERE f.commande_id = c.id)',
            [array_column($commandes, 'id')], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
        $unknown = (int) ($r['cogs']['unknownCostItems'] ?? 0);
        $alerts = [];
        if ($unknown > 0) {
            $alerts[] = ['code' => 'UNKNOWN_COST', 'count' => $unknown, 'text' => $this->tr('conversation.actions.period_close.alerts.unknown_cost', $locale, ['%count%' => (string) $unknown])];
        }
        if ($withoutShipping > 0) {
            $alerts[] = ['code' => 'ORDERS_WITHOUT_SHIPPING', 'count' => $withoutShipping, 'text' => $this->tr('conversation.actions.period_close.alerts.without_shipping', $locale, ['%count%' => (string) $withoutShipping])];
        }

        return ['period' => ['id' => (int) $period->getId(), 'name' => PeriodCalendar::displayName($period, $locale), 'from' => $period->getStartDateCollection()?->format('Y-m-d'), 'to' => $period->getEndDateCollection()?->format('Y-m-d')],
            'currency' => $this->site(),
            'result' => ['revenue' => (int) ($r['sales']['revenue'] ?? 0), 'unknownCostRevenue' => (int) ($r['sales']['unknownCostRevenue'] ?? 0), 'cogs' => (int) ($r['cogs']['amount'] ?? 0), 'unknownCostItems' => $unknown,
                'expenses' => (int) ($r['expenses']['total'] ?? 0), 'gross' => (int) ($r['result']['gross'] ?? 0), 'net' => (int) ($r['result']['net'] ?? 0)],
            'commandes' => $commandes, 'alerts' => $alerts,
            'consequence' => $this->tr('conversation.actions.period_close.consequence.text', $locale)];
    }

    public function rows(array $preview, string $locale): array
    {
        $money = ['currency' => $preview['currency']];
        $rows = [$this->row('periodId', $locale, $preview['period']['name'], 'text'), $this->row('revenue', $locale, $preview['result']['revenue'], 'money', $money),
            $this->row('cogs', $locale, $preview['result']['cogs'], 'money', $money), $this->row('expenses', $locale, $preview['result']['expenses'], 'money', $money),
            $this->row('net', $locale, $preview['result']['net'], 'money', $money), $this->row('commandes', $locale, count($preview['commandes']), 'number')];
        foreach ($preview['alerts'] as $alert) {
            $rows[] = $this->row('alerts', $locale, $alert['text'], 'text');
        }
        $rows[] = $this->row('consequence', $locale, $preview['consequence'], 'text');

        return $rows;
    }

    public function execute(array $answers, ?string $user): array
    {
        $period = $this->period($answers);
        $periodId = (int) $period->getId();
        // L'état d'avant, noté pour une annulation exacte
        $before = $this->periodState($periodId);
        $commandes = [];
        foreach ($this->openCommandes($period) as $c) {
            $commandes[] = ['id' => $c['id'], 'products' => $this->frozenQuantities($c['id'])];
        }
        $marks = ['collectionStats' => (int) $this->conn()->fetchOne('SELECT COALESCE(MAX(id), 0) FROM collection_statistiques'), 'commandeStats' => (int) $this->conn()->fetchOne('SELECT COALESCE(MAX(id), 0) FROM commande_statistiques')];
        $this->close->execute($period); // 409 ALREADY_CLOSED ; la clôture de la route existante
        $after = $this->periodState($periodId);
        foreach ($commandes as $i => $c) {
            $commandes[$i]['received'] = $this->received($c['id']); // constaté après la clôture : une réception de stock ultérieure se verra
        }
        $this->audit->record(self::AUDIT, $periodId, 'close', $before, $after + ['commandes' => array_column($commandes, 'id')]);

        return ['result' => ['periodId' => $periodId, 'closedAt' => $after['closedAt'], 'commandesClosed' => count($commandes)],
            'undo' => ['periodId' => $periodId, 'before' => $before, 'after' => $after, 'commandes' => $commandes, 'marks' => $marks]];
    }

    public function blockers(array $undo): array
    {
        $reasons = [];
        $now = $this->periodState((int) $undo['periodId']);
        if ($now === null || $now !== $undo['after']) {
            $reasons[] = ['code' => 'PERIOD_CHANGED', 'message' => 'La période a été rouverte ou clôturée autrement depuis.'];
        }
        foreach ($undo['commandes'] as $c) {
            $closed = $this->conn()->fetchOne('SELECT is_closed FROM commande WHERE id = ?', [(int) $c['id']]);
            // commande disparue (aucune ligne) ou rouverte : dans les deux cas elle n'est plus « fermée » ; une réception de stock change la quantité reçue
            if (!in_array($closed, [true, 't', 1, '1'], true) || $this->received((int) $c['id']) !== (int) $c['received']) {
                $reasons[] = ['code' => 'ORDER_CHANGED', 'message' => 'Une commande fermée par cette clôture a changé depuis (rouverte, supprimée, ou réception de stock).', 'commandeId' => (int) $c['id']];
                break;
            }
        }

        return $reasons;
    }

    public function undo(array $undo): array
    {
        $em = $this->em();
        $periodId = (int) $undo['periodId'];
        $period = $em->find(Collections::class, $periodId);
        if ($period === null) {
            throw new GestionConflictException('UNDO_BLOCKED', 'La période n\'existe plus.');
        }
        $conn = $this->conn();
        $conn->beginTransaction();
        try {
            // La période retrouve son état d'avant, « jamais rouverte » compris : la clôture par la date la refermera le moment venu
            $date = fn (?string $v) => $v === null ? null : new \DateTime($v);
            $period->setIsClosed((bool) $undo['before']['isClosed'])->setClosedAt($date($undo['before']['closedAt']))->setReopenedAt($date($undo['before']['reopenedAt']));
            $ids = array_map(fn (array $c) => (int) $c['id'], $undo['commandes']);
            foreach ($undo['commandes'] as $c) { // seules les commandes fermées par cette clôture sont rouvertes
                $commande = $em->find(Commande::class, (int) $c['id']);
                $commande?->setIsClosed(false);
                foreach ($c['products'] as $productId => $frozen) {
                    $conn->executeStatement('UPDATE product SET freeze_quantity = ? WHERE id = ?', [$frozen, (int) $productId]);
                }
            }
            $em->flush();
            // Chiffres figés créés par cette clôture, et résultat figé à une lecture ultérieure
            if ($ids !== []) {
                $conn->executeStatement('DELETE FROM commande_statistiques WHERE id > ? AND commande_id IN (?)', [(int) $undo['marks']['commandeStats'], $ids], [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::INTEGER]);
            }
            $conn->executeStatement('DELETE FROM collection_statistiques WHERE id > ? AND collection_id = ?', [(int) $undo['marks']['collectionStats'], $periodId]);
            $em->getRepository(PeriodResult::class)->deleteByCollection($period);
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
        // Ne pas vider l'unité de travail ici : le fil doit encore y enregistrer son message « annulé » (un vidage détachait la conversation
        // et l'écriture échouait, les données étant déjà remises). Les quantités figées sont écrites en SQL : aucun article n'est chargé ici.
        $this->audit->record(self::AUDIT, $periodId, 'undo', $undo['after'], $undo['before'] + ['commandes' => $ids]);

        return ['restored' => ['periodId' => $periodId, 'commandesReopened' => count($ids)]];
    }

    /** Pourquoi cette période ne se clôture pas par le fil : déjà fermée, ou pas encore terminée ; null si elle le peut */
    private function refusal(Collections $period): ?string
    {
        if ($this->calendar->isClosed($period)) {
            return 'ALREADY_CLOSED';
        }

        return $period->getEndDateCollection()?->format('Y-m-d') >= $this->calendar->today()->format('Y-m-d') ? 'PERIOD_NOT_ENDED' : null;
    }

    private function period(array $answers): Collections
    {
        $id = $answers['periodId'] ?? $this->build([], 'fr')['questions'][0]['answer']; // sans réponse : la période proposée, quand une seule est à clôturer
        $period = $id === null ? null : $this->em()->find(Collections::class, (int) $id);
        if ($period === null) {
            throw new GestionConflictException('PERIOD_NOT_FOUND', 'Période introuvable.', 404);
        }

        return $period;
    }

    /** @return list<array{id: int, name: ?string, date: ?string}> commandes fournisseurs datées dans la période et encore ouvertes : celles que la clôture fermera (même règle qu'elle) */
    private function openCommandes(Collections $period): array
    {
        $rows = $this->conn()->fetchAllAssociative('SELECT id, name, date FROM commande WHERE date >= ? AND date < ? AND COALESCE(is_closed, false) = false ORDER BY date, id',
            [$period->getStartDateCollection()->format('Y-m-d 00:00:00'), \DateTimeImmutable::createFromInterface($period->getEndDateCollection())->modify('+1 day')->format('Y-m-d 00:00:00')]);

        return array_map(fn (array $r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'date' => $r['date'] === null ? null : substr((string) $r['date'], 0, 10)], $rows);
    }

    /** @return array{isClosed: bool, closedAt: ?string, reopenedAt: ?string}|null état de clôture d'une période, tel qu'en base */
    private function periodState(int $periodId): ?array
    {
        $row = $this->conn()->fetchAssociative('SELECT is_closed, closed_at, reopened_at FROM collections WHERE id = ?', [$periodId]);

        return $row === false ? null : ['isClosed' => in_array($row['is_closed'], [true, 't', 1, '1'], true), 'closedAt' => $row['closed_at'] === null ? null : substr((string) $row['closed_at'], 0, 19),
            'reopenedAt' => $row['reopened_at'] === null ? null : substr((string) $row['reopened_at'], 0, 19)];
    }

    /** @return array<int, ?int> quantité figée de chaque article rattaché à la commande (article => valeur, vide comprise) */
    private function frozenQuantities(int $commandeId): array
    {
        $out = [];
        foreach ($this->conn()->fetchAllAssociative('SELECT id, freeze_quantity FROM product WHERE commande_id = ? ORDER BY id', [$commandeId]) as $r) {
            $out[(int) $r['id']] = $r['freeze_quantity'] === null ? null : (int) $r['freeze_quantity'];
        }

        return $out;
    }

    /** Quantité reçue en tout sur les lignes de la commande : change à chaque réception de stock ou annulation de réception */
    private function received(int $commandeId): int
    {
        return (int) $this->conn()->fetchOne('SELECT COALESCE(SUM(quantity_received), 0) FROM commande_line WHERE commande_id = ?', [$commandeId]);
    }
}
