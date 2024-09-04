<?php

namespace App\Services\StyleService;

use App\Entity\Style;
use Doctrine\ORM\EntityManagerInterface;

class StyleService
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function getAllStyles(): array
    {
        return $this->entityManager->getRepository(Style::class)->findAll();
    }
}
