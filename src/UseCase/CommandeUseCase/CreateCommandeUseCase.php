<?php
namespace App\UseCase\CommandeUseCase;

use App\Entity\Collections;
use App\Services\CommandeService\CommandeService;
use App\Dto\FournisseurInputDTO;

class CreateCommandeUseCase
{
    private $commandeService;

    public function __construct(CommandeService $commandeService)
    {
        $this->commandeService = $commandeService;
    }

    public function execute(array $data, Collections $collection, FournisseurInputDTO $fournisseurDTO)
    {
        $fournisseur = $this->commandeService->findOrCreateFournisseur($fournisseurDTO);
        return $this->commandeService->createCommande($data, $collection, $fournisseur);
    }
}
