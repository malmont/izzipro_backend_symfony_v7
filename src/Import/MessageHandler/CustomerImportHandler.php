<?php

namespace App\Import\MessageHandler;

use App\Import\CustomerImportService;
use App\Import\Message\RunCustomerImportMessage;
use App\Import\Message\SendImportInvitationsMessage;
use App\Services\Platform\TenantDatabaseConnector;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Import des clients (lot IMP-2) : exécute un import confirmé, ou envoie les invitations d'un import, sur son site. Jamais relancé :
 * un import interrompu se voit à son état et se rejoue sans doublon ; une invitation non partie reste à envoyer.
 */
final class CustomerImportHandler
{
    public function __construct(
        private readonly TenantDatabaseConnector $connector,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly CustomerImportService $imports,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsMessageHandler]
    public function run(RunCustomerImportMessage $message): void
    {
        if ($this->enter($message->tenantCode, $message->jobId)) {
            try {
                $this->imports->run($message->jobId, $message->by);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('[Import] site %s, import de clients %d en échec (%s)', $message->tenantCode, $message->jobId, $e::class), ['exception' => $e]);
            }
        }
    }

    #[AsMessageHandler]
    public function invite(SendImportInvitationsMessage $message): void
    {
        if ($this->enter($message->tenantCode, $message->jobId)) {
            $this->imports->sendInvitations($message->jobId); // ne lève jamais
        }
    }

    private function enter(string $code, int $jobId): bool
    {
        $tenant = $this->connector->tenant($code);
        if ($tenant === null) {
            $this->logger->error(sprintf('[Import] site %s introuvable, import de clients %d non traité', $code, $jobId));

            return false;
        }
        $this->emProvider->switchTenant($tenant['dbname'], $tenant['code']);

        return true;
    }
}
