<?php
namespace App\Services\CarrierService;

use App\Entity\Carrier;
use Doctrine\ORM\EntityManagerInterface;

class CarrierService
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function getAllCarriers(): array
    {
        return $this->entityManager->getRepository(Carrier::class)->findAll();
    }
}
