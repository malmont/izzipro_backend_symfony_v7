<?php
namespace App\Services\SizesService;

use App\Entity\Size;
use Doctrine\ORM\EntityManagerInterface;

class SizeService
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function getAllSizes(): array
    {
        return $this->entityManager->getRepository(Size::class)->findAll();
    }
}
