<?php
namespace App\UseCase\TransporteurUseCase;

use App\Services\TransporteurService\TransporteurService;

use App\Entity\Transporteur;

class DeleteTransporteurUseCase
{
    private TransporteurService $transporteurService;

    public function __construct(TransporteurService $transporteurService)
    {
        $this->transporteurService = $transporteurService;
    }

    public function execute(Transporteur $transporteur): void
    {
        $this->transporteurService->deleteTransporteur($transporteur);
    }
}
