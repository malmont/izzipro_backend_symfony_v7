<?php

namespace App\Services\EmailConfigurationService;

use App\Entity\EmailConfiguration;
use App\Repository\EmailConfigurationRepository;
use App\Services\TenantEntityManagerProvider;

class EmailConfigurationService
{
    public function __construct(private TenantEntityManagerProvider $emProvider)
    {
    }

    public function findOneByLocale(string $locale): ?EmailConfiguration
    {
        $em = $this->emProvider->getEntityManager();
        /** @var EmailConfigurationRepository $repo */
        $repo = $em->getRepository(EmailConfiguration::class);
        $config = $repo->findOneByLocale($locale);

        return $config ?: $repo->findOneBy([]);
    }

    public function findDefault(): ?EmailConfiguration
    {
        $em = $this->emProvider->getEntityManager();
        return $em->getRepository(EmailConfiguration::class)->findOneBy([]);
    }
}
