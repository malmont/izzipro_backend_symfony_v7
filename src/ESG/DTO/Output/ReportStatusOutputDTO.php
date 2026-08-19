<?php

namespace App\ESG\DTO\Output;

use App\ESG\Entity\DiagnosticReport;

class ReportStatusOutputDTO
{
    public string $status;
    public ?string $downloadUrl = null;
    public ?string $generatedAt = null;
    public ?string $error = null;

    public function __construct(DiagnosticReport $report)
    {
        $this->status = $report->getStatus()->value;
        $this->generatedAt = $report->getGeneratedAt() ? $report->getGeneratedAt()->format(\DateTimeInterface::ATOM) : null;
        $this->error = $report->getErrorMessage();
        
        if ($report->getStatus()->value === 'ready') {
            $uuidStr = $report->getSession()->getUuid()->toRfc4122();
            $this->downloadUrl = sprintf('/api/boussole/sessions/%s/report/download', $uuidStr);
        }
    }
}
