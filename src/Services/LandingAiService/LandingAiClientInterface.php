<?php

namespace App\Services\LandingAiService;

/**
 * Appel à l'API Messages d'Anthropic pour l'assistant des landing pages (remplacé par un faux client en test).
 */
interface LandingAiClientInterface
{
    /**
     * Envoie une requête POST /v1/messages et renvoie la réponse décodée en objets (json_decode sans tableaux).
     *
     * @throws LandingAiTimeoutException délai dépassé
     * @throws \RuntimeException erreur de l'API (message sans secret)
     */
    public function createMessage(array $payload, float $timeoutSeconds): object;
}
