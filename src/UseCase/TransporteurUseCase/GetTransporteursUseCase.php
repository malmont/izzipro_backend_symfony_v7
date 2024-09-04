<?php
namespace App\UseCase\TransporteurUseCase;

use App\Services\TransporteurService\TransporteurService;

class GetTransporteursUseCase
{
    private TransporteurService $transporteurService;

    public function __construct(TransporteurService $transporteurService)
    {
        $this->transporteurService = $transporteurService;
    }

    public function execute(): array
    {
        $transporteurs = $this->transporteurService->getAllTransporteurs();
        $transporteursData = [];

        foreach ($transporteurs as $transporteur) {
            $transporteursData[] = [
                'id' => $transporteur->getId(),
                'name' => $transporteur->getName(),
                'logo' => $transporteur->getLogo(),
                'contact' => $transporteur->getContact(),
            ];
        }

        return $transporteursData;
    }
}
