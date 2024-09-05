<?php
namespace App\UseCase\CommandeUseCase;

use App\Entity\Collections;
use App\Services\CommandeService\CommandeService;

class GetCommandesByCollectionUseCase
{
    private $commandeService;

    public function __construct(CommandeService $commandeService)
    {
        $this->commandeService = $commandeService;
    }

    public function execute(Collections $collection)
    {
        return $this->commandeService->getCommandesByCollection($collection);
    }
}
