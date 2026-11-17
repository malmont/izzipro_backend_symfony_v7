<?php

namespace App\Services\Platform\Essai\MessageHandler;

use App\Services\Platform\Essai\EssaiJobStore;
use App\Services\Platform\Essai\EssaiRunner;
use App\Services\Platform\Essai\Message\RunEssaiMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Le remplissage demandé par le bouton du web (lot ESSAI-DONNÉES) : jamais relancé ; l'échec se lit dans l'état (`failed`) */
#[AsMessageHandler]
final class RunEssaiHandler
{
    public function __construct(private readonly EssaiRunner $runner, private readonly EssaiJobStore $jobs, private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(RunEssaiMessage $message): void
    {
        $state = $this->jobs->get($message->tenantCode) ?? [];
        $this->jobs->put($message->tenantCode, ['state' => 'running', 'since' => time(), 'startedAt' => gmdate(\DATE_ATOM)] + $state);
        try {
            $report = $this->runner->run($message->tenantCode);
            $this->jobs->put($message->tenantCode, ['state' => 'done', 'since' => time(), 'finishedAt' => gmdate(\DATE_ATOM), 'report' => $report, 'error' => null] + $state + ['startedAt' => gmdate(\DATE_ATOM)]);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Essai] remplissage de %s en échec (%s)', $message->tenantCode, $e::class), ['exception' => $e]);
            $this->jobs->put($message->tenantCode, ['state' => 'failed', 'since' => time(), 'finishedAt' => gmdate(\DATE_ATOM), 'report' => [], 'error' => $e instanceof \InvalidArgumentException
                ? 'Site refusé : seulement demo ou un site d\'une agence de test. Rien n\'est écrit.'
                : 'Le remplissage a échoué en cours de route : ce qui a déjà été posé reste en place (il se complète au rejeu).'] + $state);
        }
    }
}
