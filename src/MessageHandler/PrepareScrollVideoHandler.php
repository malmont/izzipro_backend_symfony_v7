<?php

namespace App\MessageHandler;

use App\Message\PrepareScrollVideoMessage;
use App\Services\SharedMedia\ScrollVideoPreparer;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Worker Messenger « media » (seul conteneur où ffmpeg est installé) : sélectionne le tenant, puis prépare la vidéo.
 */
#[AsMessageHandler]
class PrepareScrollVideoHandler
{
    public function __construct(
        private readonly TenantConnectionManager $tenantManager,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly ScrollVideoPreparer $preparer,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(PrepareScrollVideoMessage $message): void
    {
        $tenant = $this->tenantManager->findTenantByCode($message->tenantCode);
        if ($tenant === null || empty($tenant['dbname'])) {
            $this->logger->error('Médiathèque : tenant de la vidéo introuvable', ['tenant' => $message->tenantCode, 'media' => $message->mediaId]);

            return;
        }
        $this->emProvider->switchTenant((string) $tenant['dbname'], $message->tenantCode);
        $this->preparer->run($message->mediaId);
    }
}
