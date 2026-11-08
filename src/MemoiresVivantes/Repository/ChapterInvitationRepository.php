<?php

namespace App\MemoiresVivantes\Repository;

use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Entity\ChapterInvitation;
use App\MemoiresVivantes\Entity\Contributor;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * Invitations par e-mail des chapitres (08/11/2026). Dépôt obtenu par l'EntityManager du site courant
 * (TenantEntityManagerProvider), jamais injecté.
 *
 * @extends EntityRepository<ChapterInvitation>
 */
class ChapterInvitationRepository extends EntityRepository
{
    public function findActiveFor(Chapter $chapter, ?Contributor $contributor): ?ChapterInvitation
    {
        return $this->findOpenFor($chapter, $contributor, [ChapterInvitation::STATUS_ACTIVE])[0] ?? null;
    }

    /**
     * @param list<string> $statuses
     * @return list<ChapterInvitation> les plus récentes d'abord
     */
    public function findOpenFor(Chapter $chapter, ?Contributor $contributor, array $statuses = ChapterInvitation::OPEN_STATUSES): array
    {
        $qb = $this->createQueryBuilder('i')
            ->where('i.chapter = :chapter')->setParameter('chapter', $chapter)
            ->andWhere('i.status IN (:statuses)')->setParameter('statuses', $statuses)
            ->orderBy('i.createdAt', 'DESC');
        $this->forContributor($qb, $contributor);

        return $qb->getQuery()->getResult();
    }

    /** @return list<ChapterInvitation> invitations encore ouvertes du contributeur, sur un chapitre ou sur tous */
    public function findOpenForContributor(Contributor $contributor, ?Chapter $chapter): array
    {
        $qb = $this->createQueryBuilder('i')
            ->where('i.contributor = :contributor')->setParameter('contributor', $contributor)
            ->andWhere('i.status IN (:statuses)')->setParameter('statuses', ChapterInvitation::OPEN_STATUSES);
        if ($chapter !== null) {
            $qb->andWhere('i.chapter = :chapter')->setParameter('chapter', $chapter);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<ChapterInvitation> toutes les invitations du livre, les plus récentes d'abord */
    public function findByBook(Book $book): array
    {
        return $this->createQueryBuilder('i')
            ->join('i.chapter', 'c')
            ->where('c.book = :book')->setParameter('book', $book)
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()->getResult();
    }

    /** @return list<ChapterInvitation> invitations actives dont le lien est expiré, la plus ancienne d'abord */
    public function findDue(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.status = :status')->setParameter('status', ChapterInvitation::STATUS_ACTIVE)
            ->andWhere('i.expiresAt <= :now')->setParameter('now', $now)
            ->orderBy('i.expiresAt', 'ASC')
            ->getQuery()->getResult();
    }

    /** L'adresse a demandé à ne plus recevoir de lien sur ce site */
    public function hasUnsubscribed(string $email): bool
    {
        return (int) $this->createQueryBuilder('i')->select('COUNT(i.id)')
            ->where('LOWER(i.email) = :email')->setParameter('email', mb_strtolower(trim($email)))
            ->andWhere('i.status = :status')->setParameter('status', ChapterInvitation::STATUS_UNSUBSCRIBED)
            ->getQuery()->getSingleScalarResult() > 0;
    }

    public function findOneByToken(string $token): ?ChapterInvitation
    {
        return $this->findOneBy(['unsubscribeToken' => $token]);
    }

    /**
     * Un nouveau lien partira-t-il automatiquement pour ce chapitre et ce contributeur (ou ce chapitre seul) ?
     * Identifiants tels qu'ils arrivent dans l'URL : une valeur mal formée vaut « non ».
     */
    public function renewalPending(string $chapterId, ?string $contributorId): bool
    {
        try {
            $chapter = Uuid::fromString($chapterId);
            $contributor = $contributorId === null ? null : Uuid::fromString($contributorId);
        } catch (\Throwable) {
            return false;
        }

        $qb = $this->createQueryBuilder('i')->select('COUNT(i.id)')
            ->where('i.chapter = :chapter')->setParameter('chapter', $chapter, 'uuid')
            ->andWhere('i.status = :status')->setParameter('status', ChapterInvitation::STATUS_ACTIVE)
            ->andWhere('i.renewalsUsed < :max')->setParameter('max', ChapterInvitation::RENEWALS_MAX);
        if ($contributor === null) {
            $qb->andWhere('i.contributor IS NULL');
        } else {
            $qb->andWhere('i.contributor = :contributor')->setParameter('contributor', $contributor, 'uuid');
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    private function forContributor(QueryBuilder $qb, ?Contributor $contributor): void
    {
        if ($contributor === null) {
            $qb->andWhere('i.contributor IS NULL');
        } else {
            $qb->andWhere('i.contributor = :contributor')->setParameter('contributor', $contributor);
        }
    }
}
