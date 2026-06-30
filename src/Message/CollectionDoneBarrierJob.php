<?php
// src/Message/CollectionDoneBarrierJob.php

namespace App\Message;

/**
 * Message "Barrière" de fin de collection.
 *
 * Ce message est dispatché APRÈS tous les micro-jobs d'une collection (ex: categories),
 * dans le même stream Redis (grâce au TenantRoutingMiddleware via getTenantId()).
 * Comme Redis Streams est FIFO, ce message sera traité par le worker uniquement
 * après que tous les micro-jobs précédents de la collection aient été traités et flushés.
 * Son handler déclenche alors la collection suivante dans la chaîne d'importation.
 */
class CollectionDoneBarrierJob implements TenantJobInterface
{
    public function __construct(
        private int    $tenantId,
        private string $gemsuiteToken,
        private int    $syncJobId,
        private string $completedCollectionType
    ) {
    }

    public function getTenantId(): int
    {
        return $this->tenantId;
    }

    public function getGemsuiteToken(): string
    {
        return $this->gemsuiteToken;
    }

    public function getSyncJobId(): int
    {
        return $this->syncJobId;
    }

    public function getCompletedCollectionType(): string
    {
        return $this->completedCollectionType;
    }
}
