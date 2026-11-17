<?php

namespace App\LossPrevention\Repository;

use Doctrine\ORM\EntityRepository;

/**
 * Alertes de sortie (lot L5-d, `docs/prevention-pertes.md` § 18.11) : une par zone et par minute ; rien d'une personne.
 *
 * @extends EntityRepository<\App\LossPrevention\Entity\LossAlert>
 */
class LossAlertRepository extends EntityRepository
{
    /**
     * Une pièce lue à la sortie, sans vente probable : elle rejoint l'alerte de sa zone et de sa minute, ou en ouvre une. Une ligne
     * verrouillée le temps d'ajouter la pièce : deux lots simultanés ne perdent aucune pièce. Rend l'alerte et si elle vient de naître ;
     * null si la pièce y était déjà (contrôle rejoué).
     *
     * @return array{id: int, created: bool}|null
     */
    public function record(bool $exercise, string $zone, string $minute, string $storeDay, bool $outOfHours, int $variantId, int $pieceId, ?int $requestedBy): ?array
    {
        $conn = $this->getEntityManager()->getConnection();
        $now = gmdate('Y-m-d H:i:s');
        $id = $conn->fetchOne(
            'INSERT INTO loss_alert (exercise, zone, minute, store_day, out_of_hours, articles, pieces, pushed, requested_by, created_at, updated_at)'
            . ' VALUES (:exercise, :zone, :minute, :day, :outside, :articles, :pieces, 0, :requested, :now, :now) ON CONFLICT (exercise, zone, minute, (COALESCE(requested_by, 0))) DO NOTHING RETURNING id',
            ['exercise' => $exercise ? 'true' : 'false', 'zone' => $zone, 'minute' => $minute, 'day' => $storeDay, 'outside' => $outOfHours ? 'true' : 'false',
                'articles' => json_encode([['variantId' => $variantId, 'count' => 1]]), 'pieces' => json_encode([$pieceId]), 'requested' => $requestedBy, 'now' => $now]
        );
        if ($id !== false && $id !== null) {
            return ['id' => (int) $id, 'created' => true];
        }
        $conn->beginTransaction();
        try {
            $row = $conn->fetchAssociative('SELECT id, articles, pieces FROM loss_alert WHERE exercise = ? AND zone = ? AND minute = ? AND COALESCE(requested_by, 0) = ? FOR UPDATE',
                [$exercise ? 'true' : 'false', $zone, $minute, $requestedBy ?? 0]);
            if ($row === false) {
                $conn->commit();

                return null;
            }
            $pieces = array_map('intval', json_decode((string) $row['pieces'], true) ?: []);
            if (in_array($pieceId, $pieces, true)) {
                $conn->commit();

                return null;
            }
            $pieces[] = $pieceId;
            $articles = json_decode((string) $row['articles'], true) ?: [];
            $found = false;
            foreach ($articles as &$article) {
                if ((int) $article['variantId'] === $variantId) {
                    $article['count'] = (int) $article['count'] + 1;
                    $found = true;
                }
            }
            unset($article);
            if (!$found) {
                $articles[] = ['variantId' => $variantId, 'count' => 1];
            }
            $conn->executeStatement('UPDATE loss_alert SET articles = ?, pieces = ?, updated_at = ? WHERE id = ?', [json_encode($articles), json_encode($pieces), $now, (int) $row['id']]);
            $conn->commit();

            return ['id' => (int) $row['id'], 'created' => false];
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    public function markPushed(int $id, int $count): void
    {
        $this->getEntityManager()->getConnection()->executeStatement('UPDATE loss_alert SET pushed = pushed + ? WHERE id = ?', [$count, $id]);
    }

    /**
     * Les alertes d'un jour du magasin (les plus récentes d'abord), depuis `$since` (UTC) si donné ; `$requestedBy` : les alertes d'exercice
     * d'un seul gérant (celui qui a joué la scène).
     *
     * @return list<array<string, mixed>>
     */
    public function ofDay(string $storeDay, bool $exercise, ?string $since = null, ?int $requestedBy = null): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT id, zone, minute, out_of_hours, articles, pushed, created_at, updated_at FROM loss_alert WHERE store_day = ? AND exercise = ?'
            . ($since !== null ? ' AND updated_at >= ?' : '') . ($requestedBy !== null ? ' AND requested_by = ?' : '') . ' ORDER BY minute DESC, id DESC LIMIT 200',
            [$storeDay, $exercise ? 'true' : 'false', ...($since !== null ? [$since] : []), ...($requestedBy !== null ? [$requestedBy] : [])]);
    }

    /** Une alerte porte-t-elle cette pièce ? (la fiche d'un constat « sortie sans vente » dit « alerte envoyée à … ») @return string|null la minute */
    public function minuteOfPiece(int $pieceId, string $from, string $to): ?string
    {
        $minute = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT minute FROM loss_alert WHERE NOT exercise AND minute BETWEEN ? AND ? AND (pieces)::jsonb @> to_jsonb(CAST(? AS INT)) ORDER BY minute LIMIT 1', [$from, $to, $pieceId]);

        return $minute === false ? null : (string) $minute;
    }
}
