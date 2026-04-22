<?php

namespace App\Repository;

use App\Entity\ProductPicture;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductPicture>
 *
 * @method ProductPicture|null find($id, $lockMode = null, $lockVersion = null)
 * @method ProductPicture|null findOneBy(array $criteria, array $orderBy = null)
 * @method ProductPicture[]    findAll()
 * @method ProductPicture[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ProductPictureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductPicture::class);
    }
}
