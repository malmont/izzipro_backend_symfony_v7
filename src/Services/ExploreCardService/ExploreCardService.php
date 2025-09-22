<?php
namespace App\Services\ExploreCardService;

use App\Dto\ExploreCardDto;
use App\Entity\ExploreCard; 
use App\Repository\ExploreCardRepository;
use App\Services\TenantEntityManagerProvider; 

class ExploreCardService
{
    private ExploreCardRepository $repository;

    public function __construct(private TenantEntityManagerProvider $emProvider) 
    {
        $em = $this->emProvider->getEntityManager();
        $this->repository = $em->getRepository(ExploreCard::class);
    }

    public function getAllCardsByLocale(string $host, string $locale): array
    {
        $cards = $this->repository->findAllByLocale($locale);

        return array_map(
            fn($card) => ExploreCardDto::fromEntity($card, $host, $locale),
            $cards
        );
    }
}