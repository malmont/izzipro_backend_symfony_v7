<?php

namespace App\MemoiresVivantes\EventListener;

use App\MemoiresVivantes\Entity\MemoireQuestion;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

/**
 * Les réponses des livres (mv_chapter.answers / contributor_answers) sont appariées aux questions par leur texte.
 * Quand le texte ou l'ordre d'une question change (API admin ou EasyAdmin), on répercute la modification
 * dans les réponses déjà saisies pour ne pas les détacher de leur question.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
class MemoireQuestionAnswerSyncListener
{
    /** @var array<int, array{id: int, bookType: string, theme: string, role: ?string, oldText: string, newText: string, newOrder: int}> */
    private array $pending = [];

    public function __construct(
        private readonly LoggerInterface $logger
    ) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof MemoireQuestion) {
                continue;
            }

            $changes = $uow->getEntityChangeSet($entity);
            if (!isset($changes['questionText']) && !isset($changes['displayOrder'])) {
                continue;
            }
            if (isset($changes['bookType']) || isset($changes['theme'])) {
                // Question déplacée vers un autre type ou chapitre : pas d'appariement possible
                $this->logger->warning('MemoireQuestion {id} déplacée de type/chapitre : réponses existantes non synchronisées', ['id' => $entity->getId()]);
                continue;
            }

            $this->pending[] = [
                'id' => $entity->getId(),
                'bookType' => $entity->getBookType(),
                'theme' => $entity->getTheme(),
                'role' => $entity->getRole(),
                'oldText' => $changes['questionText'][0] ?? $entity->getQuestionText(),
                'newText' => $entity->getQuestionText(),
                'newOrder' => $entity->getDisplayOrder(),
            ];
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (empty($this->pending)) {
            return;
        }

        $pending = $this->pending;
        $this->pending = [];
        $connection = $args->getObjectManager()->getConnection();

        foreach ($pending as $change) {
            try {
                $updated = $this->syncAnswers($connection, $change);
                if ($updated > 0) {
                    $this->logger->info('MemoireQuestion {id} : {count} chapitre(s) synchronisé(s)', ['id' => $change['id'], 'count' => $updated]);
                }
            } catch (\Throwable $e) {
                $this->logger->error('MemoireQuestion {id} : échec de synchronisation des réponses : {error}', ['id' => $change['id'], 'error' => $e->getMessage()]);
            }
        }
    }

    private function syncAnswers(Connection $connection, array $change): int
    {
        // Autres questions du même chapitre qui portent encore l'ancien texte (ex. même question pour plusieurs rôles)
        $otherRoles = $connection->fetchFirstColumn(
            'SELECT role FROM mv_question WHERE book_type = :type AND theme = :theme AND question_text = :text AND id <> :id',
            ['type' => $change['bookType'], 'theme' => $change['theme'], 'text' => $change['oldText'], 'id' => $change['id']]
        );

        $rows = $connection->fetchAllAssociative(
            'SELECT c.id, c.answers, c.contributor_answers FROM mv_chapter c JOIN mv_book b ON b.id = c.book_id WHERE b.type = :type AND c.theme = :theme',
            ['type' => $change['bookType'], 'theme' => $change['theme']]
        );

        $updatedChapters = 0;
        foreach ($rows as $row) {
            $answers = json_decode($row['answers'] ?? '[]', true) ?: [];
            $contributorAnswers = $row['contributor_answers'] !== null ? json_decode($row['contributor_answers'], true) : null;
            $changed = false;

            foreach ($answers as &$entry) {
                $role = $entry['role'] ?? null;
                $changed = $this->syncEntry($entry, $role === 'transversal' ? null : $role, $change, $otherRoles) || $changed;
            }
            unset($entry);

            if (is_array($contributorAnswers)) {
                foreach ($contributorAnswers as &$contrib) {
                    if (!is_array($contrib) || !is_array($contrib['answers'] ?? null)) {
                        continue;
                    }
                    foreach ($contrib['answers'] as &$entry) {
                        $changed = $this->syncEntry($entry, $contrib['role'] ?? null, $change, $otherRoles) || $changed;
                    }
                    unset($entry);
                }
                unset($contrib);
            }

            if ($changed) {
                $connection->update('mv_chapter', [
                    'answers' => json_encode($answers, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                    'contributor_answers' => $contributorAnswers !== null ? json_encode($contributorAnswers, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) : null,
                ], ['id' => $row['id']]);
                $updatedChapters++;
            }
        }

        return $updatedChapters;
    }

    private function syncEntry(mixed &$entry, ?string $entryRole, array $change, array $otherRoles): bool
    {
        if (!is_array($entry) || ($entry['question'] ?? null) !== $change['oldText']) {
            return false;
        }

        // La réponse appartient à un autre rôle que la question modifiée
        if ($change['role'] !== null && $entryRole !== null && $entryRole !== $change['role']) {
            return false;
        }

        // Une autre question garde l'ancien texte et peut correspondre à cette réponse : on ne la détache pas
        foreach ($otherRoles as $otherRole) {
            if ($otherRole === null || $entryRole === null || $otherRole === $entryRole) {
                return false;
            }
        }

        $entry['question'] = $change['newText'];
        $entry['index'] = $change['newOrder'];

        return true;
    }
}
