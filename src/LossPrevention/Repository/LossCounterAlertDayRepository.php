<?php

namespace App\LossPrevention\Repository;

use Doctrine\ORM\EntityRepository;

/**
 * Compteurs de l'alerte en caisse, par poste et par jour du magasin (lot L5-a, `docs/prevention-pertes.md` § 18.2, « B »).
 *
 * @extends EntityRepository<\App\LossPrevention\Entity\LossCounterAlertDay>
 */
class LossCounterAlertDayRepository extends EntityRepository
{
    /** Une alerte montrée sur ce poste ce jour-là ; `$proceeded` : le caissier a continué quand même. Une seule instruction (deux appels ne se perdent pas) */
    public function bump(int $registerId, string $day, bool $proceeded): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO loss_counter_alert_day (cash_register_id, store_day, shown, proceeded, updated_at) VALUES (:register, :day, 1, :proceeded, :now)'
            . ' ON CONFLICT (cash_register_id, store_day) DO UPDATE SET shown = loss_counter_alert_day.shown + 1,'
            . ' proceeded = loss_counter_alert_day.proceeded + EXCLUDED.proceeded, updated_at = EXCLUDED.updated_at',
            ['register' => $registerId, 'day' => $day, 'proceeded' => $proceeded ? 1 : 0, 'now' => gmdate('Y-m-d H:i:s')]
        );
    }

    /**
     * Les compteurs d'un jour, poste par poste (nom du poste, jamais d'une personne).
     *
     * @return list<array{cashRegisterId: int, name: string, shown: int, proceeded: int}>
     */
    public function ofDay(string $day): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT a.cash_register_id, COALESCE(r.name, \'\') AS name, a.shown, a.proceeded FROM loss_counter_alert_day a'
            . ' LEFT JOIN cash_register r ON r.id = a.cash_register_id WHERE a.store_day = ? ORDER BY a.cash_register_id', [$day]);

        return array_map(static fn (array $r): array => ['cashRegisterId' => (int) $r['cash_register_id'], 'name' => (string) $r['name'],
            'shown' => (int) $r['shown'], 'proceeded' => (int) $r['proceeded']], $rows);
    }
}
