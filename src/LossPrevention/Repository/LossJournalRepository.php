<?php

namespace App\LossPrevention\Repository;

use Doctrine\ORM\EntityRepository;

/**
 * Journal du module, en ajout seul (la base refuse toute modification).
 *
 * @extends EntityRepository<\App\LossPrevention\Entity\LossJournalEntry>
 */
class LossJournalRepository extends EntityRepository
{
    /** @param array<string, mixed> $data nombres, codes et identifiants seulement : jamais un nom, un courriel ni le texte du journal de caisse */
    public function append(string $type, ?int $userId, ?int $findingId = null, array $data = []): void
    {
        $this->getEntityManager()->getConnection()->insert('loss_journal', [
            'type' => $type, 'occurred_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'user_id' => $userId, 'finding_id' => $findingId,
            'data' => json_encode((object) $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** Ce compte a-t-il déjà une entrée de ce type sur ce constat depuis cet instant ? */
    public function existsSince(string $type, int $userId, int $findingId, \DateTimeImmutable $since): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM loss_journal WHERE finding_id = ? AND type = ? AND user_id = ? AND occurred_at >= ? LIMIT 1', [$findingId, $type, $userId, $since->format('Y-m-d H:i:s')]);
    }

    /**
     * Rang (identifiant de ligne du journal) jusqu'où le dernier courriel du jour a annoncé les constats redevenus « nouveaux » ; 0 si aucun
     * courriel n'en a encore annoncé. Lu dans la dernière ligne « courriel envoyé » (index type, id).
     */
    public function digestRenewedMark(): int
    {
        $data = $this->getEntityManager()->getConnection()->fetchOne('SELECT data FROM loss_journal WHERE type = ? ORDER BY id DESC LIMIT 1', [\App\LossPrevention\Entity\LossJournalEntry::DIGEST_SENT]);

        return $data === false ? 0 : max(0, (int) ((json_decode((string) $data, true) ?: [])['renewedUpTo'] ?? 0));
    }

    /**
     * Constats redevenus « nouveaux » depuis un rang du journal : leurs identifiants, et le rang de la dernière ligne lue (la borne que le
     * courriel posera). Une ligne par retour à « nouveau » : un constat n'est donc annoncé qu'une fois par retour.
     *
     * @return array{findingIds: list<int>, lastId: int}
     */
    public function renewedAfter(int $journalId): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative('SELECT id, finding_id FROM loss_journal WHERE type = ? AND id > ? ORDER BY id LIMIT 1000',
            [\App\LossPrevention\Entity\LossJournalEntry::FINDING_RENEWED, $journalId]);

        return ['findingIds' => array_values(array_unique(array_map('intval', array_filter(array_column($rows, 'finding_id'), fn ($v) => $v !== null)))),
            'lastId' => $rows === [] ? $journalId : (int) end($rows)['id']];
    }

    /**
     * Numéro du dernier exercice lancé (0 s'il n'y en a jamais eu), lu dans la dernière ligne « exercice lancé » du journal : elle ne se
     * supprime jamais, le numéro ne redescend donc pas même après un effacement. Lecture bornée par l'index (type, id).
     */
    public function lastExerciseNumber(): int
    {
        $data = $this->getEntityManager()->getConnection()->fetchOne('SELECT data FROM loss_journal WHERE type = ? ORDER BY id DESC LIMIT 1', [\App\LossPrevention\Entity\LossJournalEntry::EXERCISE_STARTED]);

        return $data === false ? 0 : max(0, (int) ((json_decode((string) $data, true) ?: [])['number'] ?? 0));
    }

    /** @return list<array<string, mixed>> entrées d'un constat, de la plus ancienne à la plus récente */
    public function forFinding(int $findingId): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT id, type, occurred_at, user_id, data FROM loss_journal WHERE finding_id = ? ORDER BY id', [$findingId]);
    }
}
