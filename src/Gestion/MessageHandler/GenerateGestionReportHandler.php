<?php

namespace App\Gestion\MessageHandler;

use App\Gestion\Message\GenerateGestionReportMessage;
use App\Gestion\Services\Report\GestionReportService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Worker `pdf` : bascule sur le site puis fabrique le rapport ; un échec est noté sur le rapport (jamais relancé) */
#[AsMessageHandler]
final class GenerateGestionReportHandler
{
    public function __construct(private readonly TenantConnectionManager $tenantManager, private readonly TenantEntityManagerProvider $emProvider, private readonly GestionReportService $reports, private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(GenerateGestionReportMessage $message): void
    {
        $tenant = $this->tenantManager->findTenantByCode($message->tenantCode);
        if ($tenant === null || empty($tenant['dbname'])) {
            $this->logger->error('Rapports de la gestion : site introuvable', ['tenant' => $message->tenantCode, 'report' => $message->reportId]);

            return;
        }
        $this->emProvider->switchTenant((string) $tenant['dbname'], $message->tenantCode);
        $this->reports->generate($message->reportId); // ne lève jamais : un échec est noté sur le rapport
    }
}
