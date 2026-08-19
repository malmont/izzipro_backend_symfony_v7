<?php

namespace App\ESG\Service;

use App\ESG\Entity\CertificationReferential;
use App\ESG\Entity\SubsidyProgram;

class SubsidyCalculator
{
    /**
     * @param SubsidyProgram[] $subsidyPrograms
     * @return array{programs: array<array{program: SubsidyProgram, amount: int}>, totalSubsidy: int, netCost: int}
     */
    public function calculate(CertificationReferential $cert, array $subsidyPrograms): array
    {
        $programsResult = [];
        $totalSubsidy = 0;
        $costMin = $cert->getCostMinCad();

        foreach ($subsidyPrograms as $program) {
            $amount = (int) round($costMin * ($program->getSubsidyRatePercent() / 100));
            
            if ($program->getMaxAmountCad() !== null) {
                $amount = min($amount, $program->getMaxAmountCad());
            }

            $programsResult[] = [
                'program' => $program,
                'amount' => $amount,
            ];

            $totalSubsidy += $amount;
        }

        $netCost = max(0, $costMin - $totalSubsidy);

        return [
            'programs' => $programsResult,
            'totalSubsidy' => $totalSubsidy,
            'netCost' => $netCost,
        ];
    }
}
