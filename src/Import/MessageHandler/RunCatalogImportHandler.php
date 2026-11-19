<?php

namespace App\Import\MessageHandler;

use App\Import\CatalogImportService;
use App\Import\Message\RunCatalogImportMessage;
use App\Services\Platform\TenantDatabaseConnector;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Exécute un import de catalogue sur son site. Jamais relancé : un import interrompu se voit à son état, et se rejoue sans doublon. */
#[AsMessageHandler]
final class RunCatalogImportHandler
{
    public function __construct(
        private readonly TenantDatabaseConnector $connector,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly CatalogImportService $imports,
        private readonly LoggerInterface $logger,
        private readonly ?\App\Services\Group\Copy\GroupCatalogCopyService $groupCopies = null,
    ) {
    }

    public function __invoke(RunCatalogImportMessage $message): void
    {
        $tenant = $this->connector->tenant($message->tenantCode);
        if ($tenant === null) {
            $this->logger->error(sprintf('[Import] site %s introuvable, import %d non exécuté', $message->tenantCode, $message->jobId));

            return;
        }
        $this->emProvider->switchTenant($tenant['dbname'], $tenant['code']);
        try {
            $this->imports->run($message->jobId, $message->by);
            // lot G-4 : une copie du groupe écrit sa correspondance article et variante (base maître) une fois exécutée
            $links = $this->groupCopies?->recordLinks($message->jobId) ?? 0;
            if ($links > 0) {
                $this->logger->info(sprintf('[Import] site %s, copie du groupe %d : %d lien(s) d\'articles', $message->tenantCode, $message->jobId, $links));
            }
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Import] site %s, import %d en échec (%s)', $message->tenantCode, $message->jobId, $e::class), ['exception' => $e]);
        }
    }
}
