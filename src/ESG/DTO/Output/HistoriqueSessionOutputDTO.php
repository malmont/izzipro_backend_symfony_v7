<?php

namespace App\ESG\DTO\Output;

use App\ESG\Entity\DiagnosticSession;

class HistoriqueSessionOutputDTO
{
    public string $uuid;
    public ?float $scoreEnvironment;
    public ?float $scoreGovernance;
    public ?float $scoreSocial;
    public ?float $scoreClimate;
    public ?float $scoreGlobal;
    public ?string $maturityLevel;
    public ?string $completedAt;

    public function __construct(DiagnosticSession $session)
    {
        $this->uuid = $session->getUuid()->toRfc4122();
        $this->scoreEnvironment = $session->getScoreEnvironment();
        $this->scoreGovernance = $session->getScoreGovernance();
        $this->scoreSocial = $session->getScoreSocial();
        $this->scoreClimate = $session->getScoreClimate();
        $this->scoreGlobal = $session->getScoreGlobal();
        $this->maturityLevel = $session->getMaturityLevel();
        $this->completedAt = $session->getCompletedAt() ? $session->getCompletedAt()->format(\DateTimeInterface::ATOM) : null;
    }
}
