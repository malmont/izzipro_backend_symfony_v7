<?php

namespace App\Services\ExploreCardService;

use App\Dto\ExploreCardDto;
use App\Repository\ExploreCardRepository;

class ExploreCardService
{
    public function __construct(private ExploreCardRepository $repo) {}

    /**
     * @param string $host  ex. "https://mon-domaine.com"
     * @return ExploreCardDto[]
     */
    public function getAllCards(string $host): array
    {
        $cards = $this->repo->findAll();
        $dtos = [];

        foreach ($cards as $card) {
            $base = rtrim($host, '/').'/assets/uploads/explore/';
            $imageUrl = $card->getImagePath() ? $base . $card->getImagePath() : null;
            $videoUrl = $card->getVideoPath() ? $base . $card->getVideoPath() : null;

            $dtos[] = new ExploreCardDto(
                $card->getId(),
                $card->getIsDifferent(),
                $card->getStandardTitle(),
                $card->getDifferentTitle(),
                $card->getDescription(),
                $card->getLink(),
                $imageUrl,
                $videoUrl
            );
        }

        return $dtos;
    }
}
