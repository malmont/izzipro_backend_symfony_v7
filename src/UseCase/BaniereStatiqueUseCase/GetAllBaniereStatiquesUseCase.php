<?php
namespace App\UseCase\BaniereStatiqueUseCase;

use App\Services\BaniereStatiqueService\BaniereStatiqueService;

class GetAllBaniereStatiquesUseCase
{
    public function __construct(private BaniereStatiqueService $service) {}

    public function execute(): array
    {
        return $this->service->findAll();
    }
}
