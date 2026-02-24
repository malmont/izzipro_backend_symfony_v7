<?php

namespace App\Services\EmailConfigurationService; // Adaptez le namespace si besoin

use App\Entity\EmailConfiguration;
use App\Repository\EmailConfigurationRepository;
use App\Services\TenantEntityManagerProvider;

class EmailConfigurationService
{
    private EmailConfigurationRepository $repository;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $em = $emProvider->getEntityManager();
        $this->repository = $em->getRepository(EmailConfiguration::class);
    }

    public function findOneByLocale(string $locale): ?EmailConfiguration
    {
        return $this->repository->findOneByLocale($locale);
    }
}
