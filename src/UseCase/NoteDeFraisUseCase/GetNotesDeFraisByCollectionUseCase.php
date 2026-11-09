<?php
namespace App\UseCase\NoteDeFraisUseCase;

use App\Entity\Collections;
use App\Services\NoteDeFraisService\NoteDeFraisService;
use App\Dto\NoteDeFraisOutputDTO;

class GetNotesDeFraisByCollectionUseCase
{
    private NoteDeFraisService $noteDeFraisService;

    public function __construct(NoteDeFraisService $noteDeFraisService, private readonly ?\App\Services\BoutiqueSettingsService\TenantCurrencyProvider $currency = null) // devise du site (lot G1, 09/11/2026)
    {
        $this->noteDeFraisService = $noteDeFraisService;
    }


    public function execute(Collections $collection, string $host): array
    {
        $notes = $this->noteDeFraisService->getNotesByCollection($collection);
        $currency = $this->currency?->code() ?? \App\Services\BoutiqueSettingsService\TenantCurrencyProvider::DEFAULT;

        return array_map(function($note) use ($host, $currency) {
            return new NoteDeFraisOutputDTO($note, $host, $currency);
        }, $notes);
    }
    
}
