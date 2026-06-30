<?php
// src/MessageHandler/CollectionDoneBarrierJobHandler.php

namespace App\MessageHandler;

use App\Message\CollectionDoneBarrierJob;
use App\Message\FinalizeSyncJob;
use App\Message\ImportGemsuiteCollectionJob;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Handler du message "Barrière" de fin de collection.
 *
 * Ce handler est exécuté APRÈS que tous les micro-jobs de la collection précédente
 * ont été traités (grâce à la garantie FIFO de Redis Streams dans le même stream).
 * Il est responsable de déclencher la collection suivante dans la chaîne d'importation
 * (ou de lancer la finalisation si c'était la dernière collection).
 */
#[AsMessageHandler]
class CollectionDoneBarrierJobHandler
{
    /**
     * Même chaîne d'importation que dans ImportGemsuiteCollectionJobHandler.
     * Cette constante est la source de vérité de l'ordre des collections.
     */
    private const IMPORT_CHAIN = [
        'clients_contacts',
        'categories',
        'company_config',
        'resources',
        'products',
    ];

    public function __construct(
        private MessageBusInterface $messageBus,
        private LoggerInterface     $logger,
    ) {
    }

    public function __invoke(CollectionDoneBarrierJob $message): void
    {
        $completedType = $message->getCompletedCollectionType();

        $this->logger->info(sprintf(
            '[BarrierJob] Barrière franchie pour la collection "%s" (Tenant ID %d). Déclenchement de la suivante.',
            $completedType,
            $message->getTenantId()
        ));

        $currentIndex = array_search($completedType, self::IMPORT_CHAIN);
        $nextType = ($currentIndex !== false && $currentIndex < count(self::IMPORT_CHAIN) - 1)
            ? self::IMPORT_CHAIN[$currentIndex + 1]
            : null;

        if ($nextType) {
            $this->logger->info(sprintf('[BarrierJob] Dispatch de la collection suivante : "%s"', $nextType));
            $this->messageBus->dispatch(new ImportGemsuiteCollectionJob(
                $message->getTenantId(),
                $message->getGemsuiteToken(),
                $message->getSyncJobId(),
                $nextType,
                1
            ));
        } else {
            $this->logger->info(sprintf(
                '[BarrierJob] "%s" était la dernière collection. Lancement de la finalisation.',
                $completedType
            ));
            $this->messageBus->dispatch(new FinalizeSyncJob(
                $message->getTenantId(),
                $message->getSyncJobId()
            ));
        }
    }
}
