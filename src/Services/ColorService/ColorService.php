<?php

namespace App\Services\ColorService;

use App\Entity\Color;
use App\Services\TenantEntityManagerProvider;

class ColorService
{
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function getAllColors(): array
    {
        $em = $this->emProvider->getEntityManager();
        return $em->getRepository(Color::class)->findAll();
    }
}
