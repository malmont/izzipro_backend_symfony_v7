<?php
namespace App\UseCase\NoteDeFraisUseCase;

use App\Entity\Collections;
use App\Services\NoteDeFraisService\NoteDeFraisService;
use App\Dto\NoteDeFraisOutputDTO;

class GetNotesDeFraisByCollectionUseCase
{
    private NoteDeFraisService $noteDeFraisService;

    public function __construct(NoteDeFraisService $noteDeFraisService)
    {
        $this->noteDeFraisService = $noteDeFraisService;
    }


    public function execute(Collections $collection, string $host): array
    {
        $notes = $this->noteDeFraisService->getNotesByCollection($collection);
        return array_map(function($note) use ($host) {
            return new NoteDeFraisOutputDTO($note, $host);
        }, $notes);
    }
    
}
