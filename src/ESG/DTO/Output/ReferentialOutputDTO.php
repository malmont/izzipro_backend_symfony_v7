<?php

namespace App\ESG\DTO\Output;

use App\ESG\Entity\CertificationReferential;

class ReferentialOutputDTO
{
    public string $code;
    public string $name;
    public string $category;
    public string $version;
    public float $thresholdEnvironment;
    public float $thresholdGovernance;
    public float $thresholdSocial;
    public float $thresholdClimate;
    public float $thresholdGlobal;
    public ?string $certLevel;
    public int $durationMinMonths;
    public int $durationMaxMonths;
    public int $costMinCad;
    public int $costMaxCad;
    public ?string $description;
    public ?string $marketImpact;
    public array $territory;

    public function __construct(CertificationReferential $ref)
    {
        $this->code = $ref->getCode() ?? '';
        $this->name = $ref->getName() ?? '';
        $this->category = $ref->getCategory() ?? '';
        $this->version = $ref->getVersion() ?? '';
        $this->thresholdEnvironment = $ref->getThresholdEnvironment();
        $this->thresholdGovernance = $ref->getThresholdGovernance();
        $this->thresholdSocial = $ref->getThresholdSocial();
        $this->thresholdClimate = $ref->getThresholdClimate();
        $this->thresholdGlobal = $ref->getThresholdGlobal();
        $this->certLevel = $ref->getCertLevel();
        $this->durationMinMonths = $ref->getDurationMinMonths();
        $this->durationMaxMonths = $ref->getDurationMaxMonths();
        $this->costMinCad = $ref->getCostMinCad();
        $this->costMaxCad = $ref->getCostMaxCad();
        $this->description = $ref->getDescription();
        $this->marketImpact = $ref->getMarketImpact();
        $this->territory = $ref->getTerritory();
    }
}
