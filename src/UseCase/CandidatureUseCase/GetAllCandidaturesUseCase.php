<?php
namespace App\UseCase\CandidatureUseCase;

use App\Services\CandidatureService\CandidatureService;

class GetAllCandidaturesUseCase
{
    private CandidatureService $candidatureService;
    public function __construct(CandidatureService $candidatureService) { $this->candidatureService = $candidatureService; }
    public function execute(): array { return $this->candidatureService->getAllCandidatures(); }
}