<?php

namespace App\Repository;

use App\Entity\PaymentMethod;
use Doctrine\ORM\EntityRepository; 

/**
 * N'est plus un service Symfony.
 * @extends EntityRepository<PaymentMethod>
 */
class PaymentMethodRepository extends EntityRepository
{

}