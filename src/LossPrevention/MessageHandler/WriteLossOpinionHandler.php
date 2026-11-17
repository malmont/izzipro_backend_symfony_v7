<?php

namespace App\LossPrevention\MessageHandler;

use App\LossPrevention\Message\WriteLossOpinionMessage;
use App\LossPrevention\Services\LossOpinionService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Le worker `gestion_ai` rédige l'avis de l'IA sur un constat (lot L5-c), sur le site de la demande */
#[AsMessageHandler]
final class WriteLossOpinionHandler
{
    public function __construct(private readonly TenantConnectionManager $tenantManager, private readonly TenantEntityManagerProvider $emProvider, private readonly LossOpinionService $opinions, private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(WriteLossOpinionMessage $message): void
    {
        $tenant = $this->tenantManager->findTenantByCode($message->tenantCode);
        if ($tenant === null || empty($tenant['dbname'])) {
            $this->logger->error('Prévention des pertes : avis de l\'IA, site introuvable', ['tenant' => $message->tenantCode, 'finding' => $message->findingId]);

            return;
        }
        $this->emProvider->switchTenant((string) $tenant['dbname'], $message->tenantCode);
        $this->opinions->write($message->findingId, in_array($message->locale, ['fr', 'en'], true) ? $message->locale : 'fr');
    }
}
