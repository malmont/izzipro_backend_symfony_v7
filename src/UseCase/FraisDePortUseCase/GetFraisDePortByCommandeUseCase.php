<?php
namespace App\UseCase\FraisDePortUseCase;

use App\Entity\Commande;
use App\Services\FraisDePortService\FraisDePortService;
use App\Dto\FraisDePortOutputDTO;

class GetFraisDePortByCommandeUseCase
{
    private FraisDePortService $fraisDePortService;

    public function __construct(FraisDePortService $fraisDePortService, private readonly ?\App\Services\BoutiqueSettingsService\TenantCurrencyProvider $currency = null) // devise du site (lot G1, 09/11/2026)
    {
        $this->fraisDePortService = $fraisDePortService;
    }

    public function execute(Commande $commande,string $host): ?FraisDePortOutputDTO
    {
        $fraisDePort = $this->fraisDePortService->getFraisDePortByCommande($commande);
        return $fraisDePort ? new FraisDePortOutputDTO($fraisDePort, $host, $this->currency?->code() ?? \App\Services\BoutiqueSettingsService\TenantCurrencyProvider::DEFAULT) : null;
    }
}
