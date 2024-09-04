<?php
namespace App\Services\ColorService;

use App\Entity\Color;
use Doctrine\ORM\EntityManagerInterface;

class ColorService
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function getAllColors(): array
    {
        return $this->entityManager->getRepository(Color::class)->findAll();
    }
}
