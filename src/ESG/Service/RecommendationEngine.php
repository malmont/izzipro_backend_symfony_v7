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

    /** Après toutes les certifications à obtenir (priorités 1 à 5). */
    public const PRIORITY_ALREADY_HELD = 6;

    public static function alreadyHeldNarrative(CertificationReferential $cert): string
    {
        return sprintf(
            'Vous détenez déjà %s. Pensez à préparer son renouvellement et à la valoriser auprès de vos clients et partenaires.',
            $cert->getName()
        );
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

            // Une certification déjà détenue n'est pas « à obtenir » : ni éligible, ni subventionnée
            $alreadyHeld = $session->getCompany()?->holdsCertification($cert->getCode()) ?? false;

            $isEligible = !$alreadyHeld
                && $scoreEnv >= $cert->getThresholdEnvironment()
                && $scoreGov >= $cert->getThresholdGovernance()
                && $scoreSoc >= $cert->getThresholdSocial()
                && $scoreCli >= $cert->getThresholdClimate()
                && $globalScore >= $cert->getThresholdGlobal();

            // 2. Calculate global score gap
            $gap = round($cert->getThresholdGlobal() - $globalScore, 2);
            $gapToThresholdGlobal = max(0.0, $gap); // 0 if already above

            // 3. Retrieve eligible SubsidyPrograms
            /** @var SubsidyProgram[] $programs */
            $programs = $alreadyHeld ? [] : $em->getRepository(SubsidyProgram::class)
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
            if ($alreadyHeld) {
                $priority = self::PRIORITY_ALREADY_HELD;
            } elseif ($isEligible) {
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
            $narrative = $alreadyHeld
                ? self::alreadyHeldNarrative($cert)
                : $this->generateImpactNarrative($cert, $globalScore, $ecartGlobal, $detailsDomaines, $isEligible);
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

        if ($reco->isAlreadyHeld()) {
            return self::alreadyHeldNarrative($cert);
        }

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

        // Labels lisibles
        $domaineLabels = [
            'environment'      => 'Environnement',
            'governance'       => 'Gouvernance',
            'social'           => 'Social & Communautés',
            'climate_activator'=> 'Action Climatique',
        ];

        // Domaines sous leur seuil, du plus grand écart au plus petit
        $domainesBloquants = array_filter($detailsDomaines, fn (array $d) => $d['ecart'] < 0);
        uasort($domainesBloquants, fn (array $a, array $b) => $a['ecart'] <=> $b['ecart']);

        // $ecartGlobal = score − seuil : positif ou nul, le seuil global est atteint
        if ($ecartGlobal >= 0) {
            if (empty($domainesBloquants)) {
                return sprintf('Vous atteignez le seuil global requis pour %s.', $cert->getName());
            }

            $obstacles = [];
            foreach ($domainesBloquants as $domain => $detail) {
                $obstacles[] = sprintf(
                    '%s (%s %% pour un seuil de %s %%, soit %s points manquants)',
                    $domaineLabels[$domain] ?? $domain,
                    $this->formatPoints($detail['score']),
                    $this->formatPoints($detail['seuil']),
                    $this->formatPoints(abs($detail['ecart']))
                );
            }

            return sprintf(
                'Vous dépassez de %s points le seuil global requis pour %s. Ce qui bloque encore votre éligibilité : %s %s. Concentrez vos efforts documentaires sur ce volet.',
                $this->formatPoints($ecartGlobal),
                $cert->getName(),
                count($obstacles) > 1 ? 'les domaines' : 'le domaine',
                implode(' et ', $obstacles)
            );
        }

        if ($ecartGlobal >= -15) {
            // Proche — levier principal : le domaine le plus bas par rapport à son seuil
            $ecarts = array_map(fn (array $d) => $d['ecart'], $detailsDomaines);
            $levier = array_search(min($ecarts), $ecarts, true);
            $label = $domaineLabels[$levier] ?? $levier;
            return sprintf(
                'Il vous manque %s points pour atteindre le seuil global requis pour %s. Votre levier principal est le domaine %s — concentrez vos efforts documentaires sur ce volet pour franchir le seuil.',
                $this->formatPoints(abs($ecartGlobal)),
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

    /** 37.5 → « 37,5 », 40.0 → « 40 » */
    private function formatPoints(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',') ?: '0';
    }
}
