<?php

namespace App\ESG\DTO\Output;

use App\ESG\Entity\DiagnosticSession;
use App\ESG\Enum\DomainEnum;

class SessionDetailOutputDTO
{
    public string $uuid;
    public string $status;
    public array $scores;
    public ?float $scoreGlobal;
    public ?string $maturityLevel;
    public array $answers;
    public array $recommendations;

    public function __construct(DiagnosticSession $session)
    {
        $this->uuid = $session->getUuid()->toRfc4122();
        $this->status = $session->getStatus()->value;
        
        $this->scores = [
            DomainEnum::ENVIRONMENT->value => $session->getScoreEnvironment(),
            DomainEnum::GOVERNANCE->value => $session->getScoreGovernance(),
            DomainEnum::SOCIAL->value => $session->getScoreSocial(),
            DomainEnum::CLIMATE->value => $session->getScoreClimate(),
        ];

        $this->scoreGlobal = $session->getScoreGlobal();
        $this->maturityLevel = $session->getMaturityLevel();

        $this->answers = [];
        foreach ($session->getAnswers() as $answer) {
            $this->answers[$answer->getQuestion()->getId()] = $answer->getAnswerValue();
        }

        $this->recommendations = [];
        // Recommendations already sorted by priority from RecommendationEngine
        foreach ($session->getRecommendations() as $reco) {
            $this->recommendations[] = new RecommendationOutputDTO($reco);
        }
    }
}
