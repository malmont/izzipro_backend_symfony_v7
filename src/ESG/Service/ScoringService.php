<?php

namespace App\ESG\Service;

use App\ESG\Entity\DiagnosticQuestion;
use App\ESG\Enum\DomainEnum;

class ScoringService
{
    /**
     * @param array<int, int> $answers Map of questionId => answerValue
     * @param DiagnosticQuestion[] $questions Questions for this domain
     */
    public function computeDomainScore(array $answers, array $questions, DomainEnum $domain): float
    {
        $totalPoints = 0;
        $maxPoints = 0;

        foreach ($questions as $question) {
            if ($question->getDomain() !== $domain) {
                continue;
            }

            $questionId = $question->getId();
            // If the question is answered, calculate score
            if (isset($answers[$questionId])) {
                $value = $answers[$questionId];
                $weight = $question->getWeight();

                $totalPoints += $value * $weight;
                $maxPoints += 2 * $weight; // Maximum possible value is 2
            }
        }

        if ($maxPoints === 0) {
            return 0.0;
        }

        return round(($totalPoints / $maxPoints) * 100, 2);
    }

    /**
     * @param array<int, int> $answers Map of questionId => answerValue
     * @param DiagnosticQuestion[] $allQuestions All active questions
     * @return array<string, float>
     */
    public function computeAllScores(array $answers, array $allQuestions): array
    {
        return [
            DomainEnum::ENVIRONMENT->value => $this->computeDomainScore($answers, $allQuestions, DomainEnum::ENVIRONMENT),
            DomainEnum::GOVERNANCE->value => $this->computeDomainScore($answers, $allQuestions, DomainEnum::GOVERNANCE),
            DomainEnum::SOCIAL->value => $this->computeDomainScore($answers, $allQuestions, DomainEnum::SOCIAL),
            DomainEnum::CLIMATE->value => $this->computeDomainScore($answers, $allQuestions, DomainEnum::CLIMATE),
        ];
    }

    /**
     * @param array<string, float> $domainScores
     */
    public function computeGlobalScore(array $domainScores): float
    {
        if (empty($domainScores)) {
            return 0.0;
        }

        $sum = array_sum($domainScores);
        return round($sum / count($domainScores), 2);
    }

    public function getMaturityLevel(float $globalScore): string
    {
        if ($globalScore < 40.0) {
            return 'Émergent';
        }
        if ($globalScore < 60.0) {
            return 'Développant';
        }
        if ($globalScore < 75.0) {
            return 'Confirmé';
        }
        return 'Excellence';
    }
}
