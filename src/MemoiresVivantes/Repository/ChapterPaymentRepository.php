<?php

namespace App\MemoiresVivantes\Repository;

use App\Entity\User;
use App\MemoiresVivantes\Entity\ChapterPayment;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<ChapterPayment> */
class ChapterPaymentRepository extends EntityRepository
{
    public function findByTransaction(string $store, string $transactionId): ?ChapterPayment
    {
        return $this->findOneBy(['store' => $store, 'transactionId' => $transactionId]);
    }

    /** @return list<ChapterPayment> achats réglés et pas encore affectés à un chapitre */
    public function findUnassigned(User $user): array
    {
        return $this->findBy(['user' => $user, 'chapterId' => null, 'status' => ChapterPayment::STATUS_PAID], ['purchasedAt' => 'ASC', 'id' => 'ASC']);
    }

    /** @return list<ChapterPayment> tous les achats de l'utilisateur, les plus récents d'abord */
    public function findForUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }
}
