<?php
namespace App\Services\PresentationService;

use App\Entity\Presentation;
use App\Repository\PresentationRepository;
use App\Services\TenantEntityManagerProvider;

class PresentationService
{
    private PresentationRepository $repository;

    public function __construct(private TenantEntityManagerProvider $emProvider) 
    {
        $em = $this->emProvider->getEntityManager();
        $this->repository = $em->getRepository(Presentation::class);
    }

    public function findAllByLocale(string $locale): array
    {
        return $this->repository->findAllByLocale($locale);
    }

    public function findByIdAndLocale(int $id, string $locale): ?Presentation
    {
        return $this->repository->findByIdAndLocale($id, $locale);
    }
}