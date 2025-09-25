<?php
namespace App\Services\ColorService;

use App\Entity\Color;
use App\Repository\ColorRepository;
use App\Services\TenantEntityManagerProvider;

class ColorService
{
    private ColorRepository $repository;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $em = $emProvider->getEntityManager();
        $this->repository = $em->getRepository(Color::class);
    }

    public function getAllColorsByLocale(string $locale): array
    {
        return $this->repository->findAllByLocale($locale);
    }
}