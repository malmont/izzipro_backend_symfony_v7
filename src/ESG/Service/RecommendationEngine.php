<?php

namespace App\ESG\Service;

use App\ESG\Entity\CertificationRecommendation;
use App\ESG\Entity\CertificationReferential;
use App\ESG\Entity\DiagnosticSession;
use App\ESG\Entity\SubsidyProgram;
use App\ESG\Enum\DomainEnum;
use App\ESG\Enum\TerritoryEnum;
use App\Services\TenantEntityManagerProvider;

class RecommendationEngine
{
    public function __construct(
        private readonly SubsidyCalculator $subsidyCalculator,
        private readonly TenantEntityManagerProvider $emProvider
    ) {
    }

    /**
     * @param array<string, float> $domainScores
     * @return CertificationRecommendation[]
     */
    public function generate(
        DiagnosticSession $session,
        array $domainScores,
        float $globalScore,
        TerritoryEnum $territory
    ): array {
        $em = $this->emProvider->getEntityManager();
        
        /** @var CertificationReferential[] $referentials */
        $referentials = $em->getRepository(CertificationReferential::class)->findAllActive();

        $recommendations = [];

        foreach ($referentials as $cert) {
            // 1. Check eligibility thresholds by domain
            $scoreEnv = $domainScores[DomainEnum::ENVIRONMENT->value] ?? 0.0;
            $scoreGov = $domainScores[DomainEnum::GOVERNANCE->value] ?? 0.0;
            $scoreSoc = $domainScores[DomainEnum::SOCIAL->value] ?? 0.0;
            $scoreCli = $domainScores[DomainEnum::CLIMATE->value] ?? 0.0;

            $isEligible = $scoreEnv >= $cert->getThresholdEnvironment()
                && $scoreGov >= $cert->getThresholdGovernance()
                && $scoreSoc >= $cert->getThresholdSocial()
                && $scoreCli >= $cert->getThresholdClimate()
                && $globalScore >= $cert->getThresholdGlobal();

            // 2. Calculate global score gap
            $gap = round($cert->getThresholdGlobal() - $globalScore, 2);
            $gapToThresholdGlobal = max(0.0, $gap); // 0 if already above

            // 3. Retrieve eligible SubsidyPrograms
            /** @var SubsidyProgram[] $programs */
            $programs = $em->getRepository(SubsidyProgram::class)
                ->findByCertificationAndTerritory($cert, $territory);

            // 4. Calculate simulation via SubsidyCalculator
            $simulation = $this->subsidyCalculator->calculate($cert, $programs);

            // 5. Create Recommendation
            $reco = new CertificationRecommendation();
            $reco->setSession($session);
            $reco->setReferential($cert);
            $reco->setIsEligible($isEligible);
            $reco->setGapToThresholdGlobal($gapToThresholdGlobal);
            $reco->setGrossCostCad($cert->getCostMinCad());
            $reco->setTotalSubsidyCad($simulation['totalSubsidy']);
            $reco->setNetCostCad($simulation['netCost']);
            
            // Estimate duration: average of min and max months
            $avgDuration = (int) round(($cert->getDurationMinMonths() + $cert->getDurationMaxMonths()) / 2);
            $reco->setEstimatedDurationMonths($avgDuration);

            // Add eligible programs
            foreach ($programs as $program) {
                $reco->addSubsidyProgram($program);
            }

            // Assign priority:
            // 1-2 for eligible, 3-4 for close, 5 for out of reach
            if ($isEligible) {
                // If net cost is under 5000 CAD, higher priority (1), else (2)
                $priority = ($simulation['netCost'] < 5000) ? 1 : 2;
            } else {
                if ($gapToThresholdGlobal <= 15.0) {
                    $priority = 3;
                } elseif ($gapToThresholdGlobal <= 30.0) {
                    $priority = 4;
                } else {
                    $priority = 5;
                }
            }
            $reco->setPriority($priority);

            // Generate dynamic impact narrative
            $detailsDomaines = [
                'environment' => [
                    'score' => $scoreEnv,
                    'seuil' => $cert->getThresholdEnvironment(),
                    'ecart' => $scoreEnv - $cert->getThresholdEnvironment(),
                ],
                'governance' => [
                    'score' => $scoreGov,
                    'seuil' => $cert->getThresholdGovernance(),
                    'ecart' => $scoreGov - $cert->getThresholdGovernance(),
                ],
                'social' => [
                    'score' => $scoreSoc,
                    'seuil' => $cert->getThresholdSocial(),
                    'ecart' => $scoreSoc - $cert->getThresholdSocial(),
                ],
                'climate_activator' => [
                    'score' => $scoreCli,
                    'seuil' => $cert->getThresholdClimate(),
                    'ecart' => $scoreCli - $cert->getThresholdClimate(),
                ],
            ];
            $ecartGlobal = $globalScore - $cert->getThresholdGlobal();
            $narrative = $this->generateImpactNarrative($cert, $globalScore, $ecartGlobal, $detailsDomaines, $isEligible);
            $reco->setImpactNarrative($narrative);

            $recommendations[] = $reco;
        }

        // 6. Sort: priority ASC, then netCost ASC
        usort($recommendations, function (CertificationRecommendation $a, CertificationRecommendation $b) {
            if ($a->getPriority() !== $b->getPriority()) {
                return $a->getPriority() <=> $b->getPriority();
            }
            return $a->getNetCostCad() <=> $b->getNetCostCad();
        });

        return $recommendations;
    }

    public function generateImpactNarrativeForRecommendation(CertificationRecommendation $reco): string
    {
        $session = $reco->getSession();
        $cert = $reco->getReferential();

        $scoreEnv = $session->getScoreEnvironment() ?? 0.0;
        $scoreGov = $session->getScoreGovernance() ?? 0.0;
        $scoreSoc = $session->getScoreSocial() ?? 0.0;
        $scoreCli = $session->getScoreClimate() ?? 0.0;

        $isEligible = $reco->isEligible();
        $globalScore = $session->getScoreGlobal() ?? 0.0;
        $ecartGlobal = $globalScore - $cert->getThresholdGlobal();

        $detailsDomaines = [
            'environment' => [
                'score' => $scoreEnv,
                'seuil' => $cert->getThresholdEnvironment(),
                'ecart' => $scoreEnv - $cert->getThresholdEnvironment(),
            ],
            'governance' => [
                'score' => $scoreGov,
                'seuil' => $cert->getThresholdGovernance(),
                'ecart' => $scoreGov - $cert->getThresholdGovernance(),
            ],
            'social' => [
                'score' => $scoreSoc,
                'seuil' => $cert->getThresholdSocial(),
                'ecart' => $scoreSoc - $cert->getThresholdSocial(),
            ],
            'climate_activator' => [
                'score' => $scoreCli,
                'seuil' => $cert->getThresholdClimate(),
                'ecart' => $scoreCli - $cert->getThresholdClimate(),
            ],
        ];

        return $this->generateImpactNarrative($cert, $globalScore, $ecartGlobal, $detailsDomaines, $isEligible);
    }

    private function generateImpactNarrative(
        CertificationReferential $cert,
        float $globalScore,
        float $ecartGlobal,
        array $detailsDomaines,  // ['domain' => ['score' => float, 'seuil' => float, 'ecart' => float]]
        bool $isEligible
    ): string {

        if ($isEligible) {
            return sprintf(
                'Vous atteignez tous les seuils requis pour %s. Vous pouvez engager votre dossier de certification dès maintenant.',
                $cert->getName()
            );
        }

        // Trouver le domaine le plus faible (écart négatif le plus grand)
        $domaineLePlusFaible = null;
        $ecartMin = 0;
        foreach ($detailsDomaines as $domain => $detail) {
            if ($detail['ecart'] < $ecartMin) {
                $ecartMin = $detail['ecart'];
                $domaineLePlusFaible = $domain;
            }
        }

        // Labels lisibles
        $domaineLabels = [
            'environment'      => 'Environnement',
            'governance'       => 'Gouvernance',
            'social'           => 'Social & Communautés',
            'climate_activator'=> 'Climate Activator',
        ];

        if ($ecartGlobal >= -15) {
            // Proche — message encourageant avec levier principal
            $label = $domaineLePlusFaible
                ? $domaineLabels[$domaineLePlusFaible] ?? $domaineLePlusFaible
                : 'de vos domaines clés';
            return sprintf(
                'Vous êtes à %.0f points du seuil %s. Votre levier principal est le domaine %s — concentrez vos efforts documentaires sur ce volet pour franchir le seuil.',
                abs($ecartGlobal),
                $cert->getName(),
                $label
            );
        }

        if ($ecartGlobal >= -35) {
            // Atteignable à moyen terme
            return sprintf(
                '%s est dans votre horizon à moyen terme (6 à 18 mois). Commencez par consolider vos certifications prioritaires pour progresser sur tous les domaines simultanément.',
                $cert->getName()
            );
        }

        // Hors de portée immédiate
        return sprintf(
            '%s représente un objectif à long terme. Concentrez-vous d\'abord sur les certifications pour lesquelles vous êtes déjà proche des seuils.',
            $cert->getName()
        );
    }
}
