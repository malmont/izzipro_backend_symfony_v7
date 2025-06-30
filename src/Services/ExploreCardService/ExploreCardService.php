<?php
namespace App\Services\ExploreCardService;

use App\Dto\ExploreCardDto;
use App\Entity\ExploreCard; // <-- On importe l'entité
use App\Services\TenantEntityManagerProvider; // <-- On importe notre provider

class ExploreCardService
{
    // MODIFICATION 1 : Le service ne dépend plus que du provider
    public function __construct(private TenantEntityManagerProvider $emProvider) {}

    /**
     * @param string $host  ex. "https://mon-domaine.com"
     * @return ExploreCardDto[]
     */
    public function getAllCards(string $host): array
    {
        // MODIFICATION 2 : On récupère l'EM et le repository ici
        $em = $this->emProvider->getEntityManager();
        $repo = $em->getRepository(ExploreCard::class);

        // On utilise le repository obtenu depuis l'EM du tenant
        $cards = $repo->findAll();
        $dtos = [];

        // Le reste de votre logique de création de DTO est inchangée et parfaite.
        foreach ($cards as $card) {
            $base = rtrim($host, '/').'/assets/uploads/explore/';
            $imageUrl = $card->getImagePath() ? $base . $card->getImagePath() : null;

            $dtos[] = new ExploreCardDto(
                $card->getId(),
                $card->getIsDifferent(),
                $card->getStandardTitle(),
                $card->getDifferentTitle(),
                $card->getDescription(),
                $card->getLink(),
                $imageUrl,
                $card->getVideoPath()
            );
        }

        return $dtos;
    }
}