<?php

namespace App\Import\MessageHandler;

use App\Import\Message\RunOrderImportMessage;
use App\Import\OrderImportService;
use App\Services\Platform\TenantDatabaseConnector;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Import des commandes (lot IMP-3) : exécute un import confirmé sur son site. Jamais relancé : un import interrompu se voit à son
 * état et se rejoue sans doublon.
 */
#[AsMessageHandler]
final class RunOrderImportHandler
{
    public function __construct(
        private readonly TenantDatabaseConnector $connector,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly OrderImportService $imports,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RunOrderImportMessage $message): void
    {
        $tenant = $this->connector->tenant($message->tenantCode);
        if ($tenant === null) {
            $this->logger->error(sprintf('[Import] site %s introuvable, import de commandes %d non traité', $message->tenantCode, $message->jobId));

            return;
        }
        $this->emProvider->switchTenant($tenant['dbname'], $tenant['code']);
        try {
            $this->imports->run($message->jobId, $message->by);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Import] site %s, import de commandes %d en échec (%s)', $message->tenantCode, $message->jobId, $e::class), ['exception' => $e]);
        }
    }
}
