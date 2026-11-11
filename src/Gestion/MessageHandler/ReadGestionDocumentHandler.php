<?php

namespace App\Gestion\MessageHandler;

use App\Gestion\Message\ReadGestionDocumentMessage;
use App\Gestion\Services\Document\GestionDocumentReader;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Worker `gestion_ai` : bascule sur le site puis lit le document ; les échecs sont notés sur le document (jamais relancé) */
#[AsMessageHandler]
final class ReadGestionDocumentHandler
{
    public function __construct(private readonly TenantConnectionManager $tenantManager, private readonly TenantEntityManagerProvider $emProvider, private readonly GestionDocumentReader $reader, private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(ReadGestionDocumentMessage $message): void
    {
        $tenant = $this->tenantManager->findTenantByCode($message->tenantCode);
        if ($tenant === null || empty($tenant['dbname'])) {
            $this->logger->error('Lecture de document : tenant introuvable', ['tenant' => $message->tenantCode, 'document' => $message->documentId]);

            return;
        }
        $this->emProvider->switchTenant((string) $tenant['dbname'], $message->tenantCode);
        $this->reader->read($message->documentId);
    }
}
