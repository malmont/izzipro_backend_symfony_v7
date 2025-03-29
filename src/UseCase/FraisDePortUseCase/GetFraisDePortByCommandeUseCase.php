<?php
namespace App\UseCase\FraisDePortUseCase;

use App\Entity\Commande;
use App\Services\FraisDePortService\FraisDePortService;
use App\Dto\FraisDePortOutputDTO;

class GetFraisDePortByCommandeUseCase
{
    private FraisDePortService $fraisDePortService;

    public function __construct(FraisDePortService $fraisDePortService)
    {
        $this->fraisDePortService = $fraisDePortService;
    }

    public function execute(Commande $commande,string $host): ?FraisDePortOutputDTO
    {
        $fraisDePort = $this->fraisDePortService->getFraisDePortByCommande($commande);
        return $fraisDePort ? new FraisDePortOutputDTO($fraisDePort,$host) : null;
    }
}
