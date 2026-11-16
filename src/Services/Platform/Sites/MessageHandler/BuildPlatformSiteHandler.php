<?php

namespace App\Services\Platform\Sites\MessageHandler;

use App\Services\Platform\Sites\Message\BuildPlatformSiteMessage;
use App\Services\Platform\Sites\PlatformSiteService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Déroule les étapes d'une demande de création de site (lot TYPE-1). Jamais relancé : une demande interrompue se voit et se reprend. */
#[AsMessageHandler]
final class BuildPlatformSiteHandler
{
    public function __construct(private readonly PlatformSiteService $sites, private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(BuildPlatformSiteMessage $message): void
    {
        try {
            $this->sites->process($message->requestId);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Plateforme] demande de site %d : traitement en échec (%s)', $message->requestId, $e::class), ['exception' => $e]);
        }
    }
}
