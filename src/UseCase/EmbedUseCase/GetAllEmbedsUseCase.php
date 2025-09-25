<?php
namespace App\UseCase\EmbedUseCase;

use App\Services\EmbedService\EmbedService;

class GetAllEmbedsUseCase

{
    private EmbedService $service;
    public function __construct(EmbedService $service) {
        $this->service = $service;
    }

    public function execute(): array
    {
        return $this->service->findAllEmbeds();
    }
}
