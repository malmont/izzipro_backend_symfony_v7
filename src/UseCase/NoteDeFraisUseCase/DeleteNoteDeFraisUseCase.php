<?php
namespace App\UseCase\NoteDeFraisUseCase;

use App\Entity\NoteDeFrais;
use App\Services\NoteDeFraisService\NoteDeFraisService;

class DeleteNoteDeFraisUseCase
{
    private NoteDeFraisService $noteDeFraisService;

    public function __construct(NoteDeFraisService $noteDeFraisService)
    {
        $this->noteDeFraisService = $noteDeFraisService;
    }

    public function execute(NoteDeFrais $note): void
    {
        $this->noteDeFraisService->deleteNoteDeFrais($note);
    }
}
