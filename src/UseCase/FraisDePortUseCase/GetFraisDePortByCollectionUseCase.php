<?php
namespace App\UseCase\FraisDePortUseCase;

use App\Entity\Collections;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\CommandeService\CommandeService;
use App\Services\FraisDePortService\FraisDePortService;

/** GET /api/collections/{id}/frais-de-port (lot B3 bis, 12/11/2026) : les commandes de la période avec leur frais de port, en une lecture */
class GetFraisDePortByCollectionUseCase
{
    public function __construct(
        private readonly FraisDePortService $fraisDePortService,
        private readonly CommandeService $commandeService,
        private readonly TenantCurrencyProvider $currency,
    ) {
    }

    /** @return array<string, mixed> */
    public function execute(Collections $collection, string $host): array
    {
        return $this->fraisDePortService->overview($this->commandeService->getCommandesByCollection($collection), $host, $this->currency->code());
    }
}
