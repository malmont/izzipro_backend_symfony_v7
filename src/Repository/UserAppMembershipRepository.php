<?php

namespace App\Repository;

use App\Entity\UserAppMembership;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<UserAppMembership> */
class UserAppMembershipRepository extends EntityRepository
{
    /** @return list<UserAppMembership> appartenances d'un compte, de la plus ancienne à la plus récente */
    public function findOfUser(int $userId): array
    {
        return $this->findBy(['userId' => $userId], ['joinedAt' => 'ASC', 'id' => 'ASC']);
    }
}
