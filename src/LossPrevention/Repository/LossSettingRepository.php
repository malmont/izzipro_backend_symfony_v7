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
        'last_analysis_at' => null, 'last_digest_day' => null, 'updated_at' => null, 'updated_by' => null];

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
     * @return array{previous: ?string}|null null : la journée était déjà prise ; sinon la valeur d'avant, à rendre si aucun envoi n'aboutit
     */
    public function reserveDigestDay(string $day): ?array
    {
        $row = $this->row(true); // crée la ligne au besoin
        $touched = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE loss_setting SET last_digest_day = ? WHERE id = ? AND last_digest_day IS DISTINCT FROM ?', [$day, $row['id'], $day]);

        return $touched === 1 ? ['previous' => $row['last_digest_day'] !== null ? (string) $row['last_digest_day'] : null] : null;
    }

    /** Rend la journée réservée (aucun envoi n'a abouti) : la valeur d'avant revient, seulement si la réservation est encore la nôtre */
    public function releaseDigestDay(string $day, ?string $previous): void
    {
        $this->getEntityManager()->getConnection()->executeStatement('UPDATE loss_setting SET last_digest_day = ? WHERE last_digest_day = ?', [$previous, $day]);
    }
}
