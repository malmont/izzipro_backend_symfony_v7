<?php
namespace App\UseCase\ExploreCardUseCase;

use App\Dto\ExploreCardDto;
use App\Services\ExploreCardService\ExploreCardService;

class GetExploreCardUseCase
{
    public function __construct(private ExploreCardService $service) {}

    /**
     * @param string $host
     * @return ExploreCardDto[]
     */
    public function execute(string $host): array
    {
        return $this->service->getAllCards($host);
    }
}
