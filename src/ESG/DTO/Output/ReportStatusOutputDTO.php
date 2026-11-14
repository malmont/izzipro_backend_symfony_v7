<?php

namespace App\ESG\DTO\Output;

use App\ESG\Entity\DiagnosticReport;

class ReportStatusOutputDTO
{
    /**
     * Ce que le client lit quand la génération du rapport a échoué (ERREURS-3, docs/erreurs-client.md). Le worker garde en base le
     * texte brut de l'exception (`esg_diag_report.error_message`, pour le diagnostic) : il ne sort jamais dans une réponse.
     */
    public const FAILURE_MESSAGE = 'La génération du rapport a échoué. Veuillez réessayer dans quelques instants.';

    public string $status;
    public ?string $downloadUrl = null;
    public ?string $generatedAt = null;
    public ?string $error = null;

    public function __construct(DiagnosticReport $report)
    {
        $this->status = $report->getStatus()->value;
        $this->generatedAt = $report->getGeneratedAt() ? $report->getGeneratedAt()->format(\DateTimeInterface::ATOM) : null;
        $this->error = $report->getErrorMessage() === null ? null : self::FAILURE_MESSAGE;
        
        if ($report->getStatus()->value === 'ready') {
            $uuidStr = $report->getSession()->getUuid()->toRfc4122();
            $this->downloadUrl = sprintf('/api/boussole/sessions/%s/report/download', $uuidStr);
        }
    }
}
