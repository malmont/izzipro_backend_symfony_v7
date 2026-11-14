<?php

namespace App\LossPrevention\Repository;

use Doctrine\ORM\EntityRepository;

/**
 * Réglages et curseur d'analyse du module : une seule ligne par site, créée à la première lecture. SQL direct (ligne verrouillée par
 * l'analyse) ; jamais de cache.
 *
 * @extends EntityRepository<\App\LossPrevention\Entity\LossSetting>
 */
class LossSettingRepository extends EntityRepository
{
    /** @return array<string, mixed> la ligne du site, créée avec les valeurs par défaut si elle manque */
    public function row(bool $forUpdate = false): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = 'SELECT * FROM loss_setting ORDER BY id LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
        $row = $conn->fetchAssociative($sql);
        if ($row === false) { // première lecture du site seulement (pas d'écriture, ni de numéro de séquence consommé, aux lectures suivantes)
            $conn->executeStatement('INSERT INTO loss_setting (retention_findings_months, retention_confirmed_months) VALUES (24, 60) ON CONFLICT DO NOTHING');
            $row = $conn->fetchAssociative($sql);
        }

        return $row ?: [];
    }

    /** @param array<string, mixed> $fields colonne => valeur (noms de colonnes fixés par l'appelant, jamais par l'entrée) */
    public function update(array $fields): void
    {
        $row = $this->row();
        $this->getEntityManager()->getConnection()->update('loss_setting', $fields, ['id' => $row['id']], array_map(
            fn ($v) => is_bool($v) ? \Doctrine\DBAL\ParameterType::BOOLEAN : \Doctrine\DBAL\ParameterType::STRING,
            array_filter($fields, fn ($v) => $v !== null)
        ));
    }
}
