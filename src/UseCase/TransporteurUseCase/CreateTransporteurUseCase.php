<?php
namespace App\UseCase\TransporteurUseCase;

use App\Dto\TransporteurDTO;
use App\Services\TransporteurService\TransporteurService;

class CreateTransporteurUseCase
{
    private TransporteurService $transporteurService;

    public function __construct(TransporteurService $transporteurService)
    {
        $this->transporteurService = $transporteurService;
    }

    public function execute(TransporteurDTO $transporteurDTO)
    {
        return $this->transporteurService->createTransporteur(
            $transporteurDTO->name,
            $transporteurDTO->logo,
            $transporteurDTO->contact
        );
    }
}
