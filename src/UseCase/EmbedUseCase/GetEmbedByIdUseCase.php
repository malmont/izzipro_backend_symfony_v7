<?php
namespace App\UseCase\EmbedUseCase;

use App\Entity\Embed;
use App\Services\EmbedService\EmbedService;

class GetEmbedByIdUseCase
{
    private EmbedService $service;
    public function __construct(EmbedService $service) {
        $this->service = $service;
    }

    public function execute(int $id): ?Embed
    {
        return $this->service->findEmbedById($id);
    }
}
