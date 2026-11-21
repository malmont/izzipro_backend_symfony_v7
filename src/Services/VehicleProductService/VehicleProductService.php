<?php

namespace App\Services\VehicleProductService;

use App\Entity\VehicleProduct;
use App\Repository\VehicleProductRepository;
use App\Services\TenantEntityManagerProvider;

/** Produits véhicules du site courant (lecture seule) */
class VehicleProductService
{
    public const PER_PAGE = 24;

    public function __construct(private TenantEntityManagerProvider $emProvider)
    {
    }

    /** @return VehicleProduct[] */
    public function page(int $page): array
    {
        return $this->repository()->findPage($page, self::PER_PAGE);
    }

    public function find(int $id): ?VehicleProduct
    {
        return $this->repository()->findOneWithBooking($id);
    }

    private function repository(): VehicleProductRepository
    {
        /** @var VehicleProductRepository */
        return $this->emProvider->getEntityManager()->getRepository(VehicleProduct::class);
    }
}
