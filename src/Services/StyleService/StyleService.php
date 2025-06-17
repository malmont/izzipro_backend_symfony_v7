<?php

namespace App\Services\StyleService;

use App\Entity\Style;
use App\Services\TenantEntityManagerProvider;

class StyleService
{
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function getAllStyles(): array
    {
        $em = $this->emProvider->getEntityManager();
        return $em->getRepository(Style::class)->findAll();
    }
}
