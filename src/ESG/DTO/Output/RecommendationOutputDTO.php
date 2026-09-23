<?php

namespace App\ESG\DTO\Output;

use App\ESG\Entity\CertificationRecommendation;
use App\ESG\Service\RecommendationEngine;

class RecommendationOutputDTO
{
    public int $priority;
    public bool $isEligible;
    public bool $alreadyHeld;
    public float $gapToThresholdGlobal;
    public int $estimatedDurationMonths;
    public array $certification;
    public array $simulation;
    public array $subsidies;
    public string $impactNarrative = '';

    public function __construct(CertificationRecommendation $reco)
    {
        $this->priority = $reco->getPriority();
        $this->isEligible = $reco->isEligible();
        $this->gapToThresholdGlobal = $reco->getGapToThresholdGlobal();
        $this->estimatedDurationMonths = $reco->getEstimatedDurationMonths();
        $this->impactNarrative = $reco->getImpactNarrative() ?? '';
        $this->alreadyHeld = $reco->isAlreadyHeld();

        $ref = $reco->getReferential();
        $this->certification = [
            'code' => $ref->getCode(),
            'name' => $ref->getName(),
            'category' => $ref->getCategory(),
            'certLevel' => $ref->getCertLevel(),
            'costMinCad' => $ref->getCostMinCad(),
            'costMaxCad' => $ref->getCostMaxCad(),
            'durationMinMonths' => $ref->getDurationMinMonths(),
            'durationMaxMonths' => $ref->getDurationMaxMonths(),
            'description' => $ref->getDescription(),
            'marketImpact' => $ref->getMarketImpact(),
            'territory' => $ref->getTerritory(),
        ];

        $this->simulation = [
            'grossCost' => $reco->getGrossCostCad(),
            'totalSubsidy' => $reco->getTotalSubsidyCad(),
            'netCost' => $reco->getNetCostCad(),
        ];

        $this->subsidies = [];
        foreach ($reco->getSubsidyPrograms() as $prog) {
            $this->subsidies[] = [
                'code' => $prog->getCode(),
                'name' => $prog->getName(),
                'organism' => $prog->getOrganism(),
                'subsidyRatePercent' => $prog->getSubsidyRatePercent(),
                'maxAmountCad' => $prog->getMaxAmountCad(),
            ];
        }

        // Recommandations enregistrées avant que la certification soit connue comme détenue :
        // même traitement que RecommendationEngine::generate()
        if ($this->alreadyHeld) {
            $this->isEligible = false;
            $this->priority = RecommendationEngine::PRIORITY_ALREADY_HELD;
            $this->impactNarrative = RecommendationEngine::alreadyHeldNarrative($ref);
            $this->simulation = ['grossCost' => $reco->getGrossCostCad(), 'totalSubsidy' => 0, 'netCost' => $reco->getGrossCostCad()];
            $this->subsidies = [];
        }
    }
}
