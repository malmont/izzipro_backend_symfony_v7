<?php

namespace App\UseCase\SocialNetworkUseCase;

use App\Dto\SocialNetworkDto;
use App\Entity\Entreprise;
use App\Services\SocialNetworkService\SocialNetworkService;
use App\Services\TenantEntityManagerProvider;

class GetSocialNetworksUseCase
{
    public function __construct(
        private SocialNetworkService $socialNetworkService,
        private TenantEntityManagerProvider $emProvider
    ) {
    }

    /**
     * @return SocialNetworkDto[]
     */
    public function execute(): array
    {
        $em = $this->emProvider->getEntityManager();
        $entreprise = $em->getRepository(Entreprise::class)->findOneBy([]);
        if (!$entreprise) {
            return [];
        }

        $socialNetworks = $this->socialNetworkService->getSocialNetworksByEntreprise($entreprise);

        return array_map(
            fn($sn) => SocialNetworkDto::fromEntity($sn),
            $socialNetworks
        );
    }
}
