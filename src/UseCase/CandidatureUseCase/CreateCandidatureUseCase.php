<?php
namespace App\UseCase\CandidatureUseCase;

use App\Dto\CandidatureInputDto;
use App\Entity\Candidature;
use App\Services\CandidatureService\CandidatureService;

class CreateCandidatureUseCase
{
    private CandidatureService $candidatureService;
    public function __construct(CandidatureService $candidatureService) { $this->candidatureService = $candidatureService; }
    public function execute(CandidatureInputDto $dto): Candidature { return $this->candidatureService->createCandidature($dto); }
}
