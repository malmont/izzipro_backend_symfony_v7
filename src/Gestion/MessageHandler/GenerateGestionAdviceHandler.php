<?php

namespace App\Gestion\MessageHandler;

use App\Gestion\Message\GenerateGestionAdviceMessage;
use App\Gestion\Services\AdviceWriter;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Worker `gestion_ai` : bascule sur le site puis fait rédiger les conseils du guide ; les échecs sont notés sur la génération (jamais relancé) */
#[AsMessageHandler]
final class GenerateGestionAdviceHandler
{
    public function __construct(private readonly TenantConnectionManager $tenantManager, private readonly TenantEntityManagerProvider $emProvider, private readonly AdviceWriter $writer, private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(GenerateGestionAdviceMessage $message): void
    {
        $tenant = $this->tenantManager->findTenantByCode($message->tenantCode);
        if ($tenant === null || empty($tenant['dbname'])) {
            $this->logger->error('Guide de la gestion : tenant introuvable', ['tenant' => $message->tenantCode, 'run' => $message->runId]);

            return;
        }
        $this->emProvider->switchTenant((string) $tenant['dbname'], $message->tenantCode);
        $this->writer->write($message->runId, $message->tenantCode);
    }
}
