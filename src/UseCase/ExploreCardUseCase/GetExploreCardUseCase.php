<?php
namespace App\UseCase\ExploreCardUseCase;

use App\Dto\ExploreCardDto;
use App\Services\ExploreCardService\ExploreCardService;

class GetExploreCardUseCase
{
    public function __construct(private ExploreCardService $service) {}

    /**
     * @return ExploreCardDto[]
     */
    public function execute(string $host, string $locale): array
    {
        return $this->service->getAllCardsByLocale($host, $locale);
    }
}