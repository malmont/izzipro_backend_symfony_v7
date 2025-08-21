<?php
namespace App\UseCase\BaniereStatiqueUseCase;

use App\Entity\BaniereStatique;
use App\Services\BaniereStatiqueService\BaniereStatiqueService;

class GetBaniereStatiqueByIdUseCase
{
    public function __construct(private BaniereStatiqueService $service) {}

    public function execute(int $id): ?BaniereStatique
    {
        return $this->service->findById($id);
    }
}

