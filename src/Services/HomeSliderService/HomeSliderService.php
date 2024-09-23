<?php
namespace App\Services\HomeSliderService;

use App\Entity\HomeSlider;
use Doctrine\ORM\EntityManagerInterface;

class HomeSliderService 
{
    private EntityManagerInterface $entityManager; // Corrigé ici

    public function __construct(EntityManagerInterface $entityManager) // Corrigé ici
    {
        $this->entityManager = $entityManager; // Corrigé ici
    }

    public function getAllhomeSliders(): array
    {
        return $this->entityManager->getRepository(HomeSlider::class)->findAll(); 
    } 
}
