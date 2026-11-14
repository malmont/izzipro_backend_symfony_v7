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

    /** @return list<array<string, mixed>> entrées d'un constat, de la plus ancienne à la plus récente */
    public function forFinding(int $findingId): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT id, type, occurred_at, user_id, data FROM loss_journal WHERE finding_id = ? ORDER BY id', [$findingId]);
    }
}
