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
}
