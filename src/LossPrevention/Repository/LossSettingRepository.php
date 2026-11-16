<?php

namespace App\LossPrevention\Repository;

use Doctrine\ORM\EntityRepository;

/**
 * Réglages et curseur d'analyse du module : une seule ligne par site, créée au premier enregistrement ou à la première analyse, jamais par
 * une lecture. SQL direct (ligne verrouillée par l'analyse) ; jamais de cache.
 *
 * @extends EntityRepository<\App\LossPrevention\Entity\LossSetting>
 */
class LossSettingRepository extends EntityRepository
{
    /** Valeurs d'un site qui n'a pas encore sa ligne : une LECTURE ne la crée pas (rien n'est écrit sur un site qui ne se sert pas du module) */
    private const DEFAULTS = ['id' => null, 'staff_notice_confirmed_at' => null, 'staff_notice_confirmed_by' => null, 'person_patterns_enabled' => null,
        'retention_findings_months' => 24, 'retention_confirmed_months' => 60, 'cursor_sequence' => null, 'backfill_until_sequence' => null, 'analysis_floor_sequence' => null,
        'last_analysis_at' => null, 'last_digest_day' => null, 'last_digest_finding_id' => null, 'updated_at' => null, 'updated_by' => null, 'silent_after_seconds' => null,
        'counter_window_seconds' => null, 'catch_up_seconds' => null, 'presence_retention_days' => null, 'zone_arrive_seconds' => null, 'zone_leave_seconds' => null, 'exit_cursor' => null];

    /**
     * La ligne du site. Lecture simple : les valeurs par défaut si elle n'existe pas encore, sans rien écrire. `$forUpdate` (l'analyse, un
     * enregistrement) : la ligne est créée au besoin, puis verrouillée.
     *
     * @return array<string, mixed>
     */
    public function row(bool $forUpdate = false): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = 'SELECT * FROM loss_setting ORDER BY id LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
        $row = $conn->fetchAssociative($sql);
        if ($row === false && $forUpdate) {
            $conn->executeStatement('INSERT INTO loss_setting (retention_findings_months, retention_confirmed_months) VALUES (24, 60) ON CONFLICT DO NOTHING');
            $row = $conn->fetchAssociative($sql);
        }

        return $row ?: self::DEFAULTS;
    }

    /** @param array<string, mixed> $fields colonne => valeur (noms de colonnes fixés par l'appelant, jamais par l'entrée) */
    public function update(array $fields): void
    {
        $row = $this->row(true); // crée la ligne au premier enregistrement
        $this->getEntityManager()->getConnection()->update('loss_setting', $fields, ['id' => $row['id']], array_map(
            fn ($v) => is_bool($v) ? \Doctrine\DBAL\ParameterType::BOOLEAN : \Doctrine\DBAL\ParameterType::STRING,
            array_filter($fields, fn ($v) => $v !== null)
        ));
    }

    /**
     * Réserve la journée du courriel de résumé : une écriture conditionnelle, que seul le premier de deux lancements simultanés obtient
     * (le second attend le verrou de la ligne, puis relit la condition : la journée est déjà posée, rien n'est touché).
     *
     * Avec la journée, la même écriture pose `last_digest_finding_id` : le dernier constat existant au moment de l'envoi. Le courriel
     * suivant n'annonce que les constats nés après lui (décision du propriétaire du 14/11/2026).
     *
     * @return array{previous: ?string, previousFindingId: ?int}|null null : la journée était déjà prise ; sinon les valeurs d'avant, à rendre
     *                                                                 si aucun envoi n'aboutit
     */
    public function reserveDigestDay(string $day, ?int $lastFindingId = null): ?array
    {
        $row = $this->row(true); // crée la ligne au besoin
        // UNE instruction : elle verrouille la ligne, lit les valeurs d'avant et écrit les nouvelles. Lues à part (hors transaction, le verrou
        // d'une lecture tombe aussitôt), les valeurs d'avant pouvaient être celles d'un autre lancement : un envoi en échec les aurait
        // « rendues » fausses
        $old = $this->getEntityManager()->getConnection()->fetchAssociative(
            'WITH old AS (SELECT id, last_digest_day, last_digest_finding_id FROM loss_setting WHERE id = ? FOR UPDATE)'
            . ' UPDATE loss_setting s SET last_digest_day = ?, last_digest_finding_id = COALESCE(?, s.last_digest_finding_id) FROM old'
            . ' WHERE s.id = old.id AND old.last_digest_day IS DISTINCT FROM ? RETURNING old.last_digest_day AS previous, old.last_digest_finding_id AS previous_finding',
            [$row['id'], $day, $lastFindingId, $day],
            [2 => $lastFindingId === null ? \Doctrine\DBAL\ParameterType::NULL : \Doctrine\DBAL\ParameterType::INTEGER]);

        return $old === false ? null : ['previous' => $old['previous'] !== null ? (string) $old['previous'] : null,
            'previousFindingId' => $old['previous_finding'] !== null ? (int) $old['previous_finding'] : null];
    }

    /** Rend la journée réservée (aucun envoi n'a abouti) : les valeurs d'avant reviennent, seulement si la réservation est encore la nôtre */
    public function releaseDigestDay(string $day, ?string $previous, ?int $previousFindingId = null): void
    {
        $this->getEntityManager()->getConnection()->executeStatement('UPDATE loss_setting SET last_digest_day = ?, last_digest_finding_id = ? WHERE last_digest_day = ?',
            [$previous, $previousFindingId, $day], [1 => $previousFindingId === null ? \Doctrine\DBAL\ParameterType::NULL : \Doctrine\DBAL\ParameterType::INTEGER]);
    }
}
