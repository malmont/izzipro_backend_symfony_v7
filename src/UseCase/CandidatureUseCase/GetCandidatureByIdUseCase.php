<?php

namespace App\UseCase\CandidatureUseCase;

use App\Entity\Candidature;
use App\Services\CandidatureService\CandidatureService; 

class GetCandidatureByIdUseCase
{
    public function __construct(
        private CandidatureService $candidatureService 
    ) {
    }


    public function execute(int $id): ?Candidature
    {
        return $this->candidatureService->findCandidature($id);
    }
}
