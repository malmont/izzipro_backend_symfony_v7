<?php

namespace App\Message;

/** Tâche de fond de l'assistant IA des landing pages (voir App\Entity\AiJob) */
final class LandingAiJobMessage
{
    public function __construct(
        public readonly string $jobId,
        public readonly string $tenantCode
    ) {
    }
}
