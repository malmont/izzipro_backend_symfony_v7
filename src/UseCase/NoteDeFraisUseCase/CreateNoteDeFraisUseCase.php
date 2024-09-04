<?php
namespace App\UseCase\NoteDeFraisUseCase;

use App\Entity\Collections;
use App\Dto\NoteDeFraisInputDTO;
use App\Services\NoteDeFraisService\NoteDeFraisService;

class CreateNoteDeFraisUseCase
{
    private NoteDeFraisService $noteDeFraisService;

    public function __construct(NoteDeFraisService $noteDeFraisService)
    {
        $this->noteDeFraisService = $noteDeFraisService;
    }

    public function execute(Collections $collection, NoteDeFraisInputDTO $inputDTO): void
    {
        $this->noteDeFraisService->createNoteDeFrais($collection, $inputDTO);
    }
}
