<?php
namespace App\UseCase\NoteDeFraisUseCase;

use App\Entity\NoteDeFrais;
use App\Dto\NoteDeFraisInputDTO;
use App\Services\NoteDeFraisService\NoteDeFraisService;

class UpdateNoteDeFraisUseCase
{
    private NoteDeFraisService $noteDeFraisService;

    public function __construct(NoteDeFraisService $noteDeFraisService)
    {
        $this->noteDeFraisService = $noteDeFraisService;
    }

    public function execute(NoteDeFrais $note, NoteDeFraisInputDTO $inputDTO): void
    {
        $this->noteDeFraisService->updateNoteDeFrais($note, $inputDTO);
    }
}
