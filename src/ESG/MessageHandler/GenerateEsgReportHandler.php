<?php

namespace App\ESG\MessageHandler;

use App\ESG\Entity\DiagnosticReport;
use App\ESG\Enum\ReportStatusEnum;
use App\ESG\Message\GenerateEsgReportMessage;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Dompdf\Dompdf;
use Dompdf\Options;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Twig\Environment as TwigEnvironment;

#[AsMessageHandler]
class GenerateEsgReportHandler
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionManager $tenantManager,
        private readonly TwigEnvironment $twig,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir
    ) {
    }

    public function __invoke(GenerateEsgReportMessage $message): void
    {
        // 1. Find tenant configuration in master tenants table
        $pdoMaster = $this->tenantManager->getPdoMaster();
        $stmt = $pdoMaster->prepare('SELECT dbname, code FROM tenants WHERE code = :code');
        $stmt->execute(['code' => $message->tenantCode]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row || !is_array($row)) {
            $this->logger->error(sprintf("GenerateEsgReportHandler: Tenant '%s' non trouvé.", $message->tenantCode));
            return;
        }

        // 2. Switch connection
        $this->logger->info(sprintf("GenerateEsgReportHandler: Switching to tenant DB '%s'", $row['dbname']));
        $this->emProvider->switchTenant((string)$row['dbname'], (string)$row['code']);
        $em = $this->emProvider->getEntityManager();

        // 3. Load DiagnosticReport
        /** @var DiagnosticReport|null $report */
        $report = $em->getRepository(DiagnosticReport::class)->find($message->reportId);
        if (!$report) {
            $this->logger->error(sprintf("GenerateEsgReportHandler: Report '%d' non trouvé.", $message->reportId));
            return;
        }

        // 4. Update status to GENERATING
        $report->setStatus(ReportStatusEnum::GENERATING);
        $em->flush();

        try {
            $session = $report->getSession();
            $company = $session->getCompany();
            $answers = $session->getAnswers();
            $recommendations = $session->getRecommendations();
            $sessionUuid = $session->getUuid()->toRfc4122();

            // 5. Render template Twig
            $html = $this->twig->render('esg/report.html.twig', [
                'session' => $session,
                'company' => $company,
                'answers' => $answers,
                'recommendations' => $recommendations,
            ]);

            // 6. Generate PDF via Dompdf
            $options = new Options();
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isRemoteEnabled', true);
            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            $pdfOutput = $dompdf->output();

            // 7. Save PDF file
            $pdfDir = $this->projectDir . '/public/esg/reports';
            if (!is_dir($pdfDir)) {
                mkdir($pdfDir, 0777, true);
            }
            $pdfPath = $pdfDir . '/' . $sessionUuid . '.pdf';
            file_put_contents($pdfPath, $pdfOutput);

            // 8. Update Report details
            $report->setStatus(ReportStatusEnum::READY);
            $report->setFilePath('/esg/reports/' . $sessionUuid . '.pdf');
            $report->setFileSize(strlen($pdfOutput));
            $report->setGeneratedAt(new \DateTimeImmutable());
            $em->flush();

            $this->logger->info(sprintf("GenerateEsgReportHandler: Report '%d' généré avec succès à '%s'.", $message->reportId, $pdfPath));

        } catch (\Throwable $e) {
            $this->logger->error(sprintf("GenerateEsgReportHandler Error: %s", $e->getMessage()), ['exception' => $e]);
            
            $report->setStatus(ReportStatusEnum::FAILED);
            $report->setErrorMessage($e->getMessage());
            $em->flush();
        }
    }
}
