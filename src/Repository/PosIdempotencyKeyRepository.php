<?php

namespace App\Repository;

use Doctrine\ORM\EntityRepository;

/** Clés d'idempotence de caisse (lecture) ; l'écriture passe par PosIdempotencyService */
class PosIdempotencyKeyRepository extends EntityRepository
{
}
