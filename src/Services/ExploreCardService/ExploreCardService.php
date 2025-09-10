<?php
namespace App\Services\ExploreCardService;

use App\Dto\ExploreCardDto;
use App\Entity\ExploreCard; 
use App\Services\TenantEntityManagerProvider; 

class ExploreCardService
{
    public function __construct(private TenantEntityManagerProvider $emProvider) {}

    /**
     * @param string $host      ex. "https://mon-domaine.com"
     * @param string $locale    ex. "fr", "en"
     * @return ExploreCardDto[]
     */
    public function getAllCards(string $host, string $locale): array
    {
        $em = $this->emProvider->getEntityManager();
        $repo = $em->getRepository(ExploreCard::class);

        $cards = $repo->findAll();
        return array_map(
            fn($card) => ExploreCardDto::fromEntity($card, $host, $locale),
            $cards
        );
    }
}