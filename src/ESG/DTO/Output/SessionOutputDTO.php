<?php

namespace App\ESG\DTO\Output;

use App\ESG\Entity\DiagnosticSession;

class SessionOutputDTO
{
    public string $uuid;
    public string $status;
    public ?float $scoreEnvironment;
    public ?float $scoreGovernance;
    public ?float $scoreSocial;
    public ?float $scoreClimate;
    public ?float $scoreGlobal;
    public ?string $maturityLevel;
    public ?string $completedAt;
    public string $createdAt;

    public function __construct(DiagnosticSession $session)
    {
        $this->uuid = $session->getUuid()->toRfc4122();
        $this->status = $session->getStatus()->value;
        $this->scoreEnvironment = $session->getScoreEnvironment();
        $this->scoreGovernance = $session->getScoreGovernance();
        $this->scoreSocial = $session->getScoreSocial();
        $this->scoreClimate = $session->getScoreClimate();
        $this->scoreGlobal = $session->getScoreGlobal();
        $this->maturityLevel = $session->getMaturityLevel();
        $this->completedAt = $session->getCompletedAt() ? $session->getCompletedAt()->format(\DateTimeInterface::ATOM) : null;
        $this->createdAt = $session->getCreatedAt()->format(\DateTimeInterface::ATOM);
    }
}
