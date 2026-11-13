<?php

namespace App\Gestion\MessageHandler;

use App\Gestion\Message\AnswerConsoleQuestionMessage;
use App\Gestion\Services\Console\ConsoleResponder;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Worker `gestion_ai` : bascule sur le site puis fait répondre la console ; un échec est noté sur la réponse (jamais relancé) */
#[AsMessageHandler]
final class AnswerConsoleQuestionHandler
{
    public function __construct(private readonly TenantConnectionManager $tenantManager, private readonly TenantEntityManagerProvider $emProvider, private readonly ConsoleResponder $responder, private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(AnswerConsoleQuestionMessage $message): void
    {
        $tenant = $this->tenantManager->findTenantByCode($message->tenantCode);
        if ($tenant === null || empty($tenant['dbname'])) {
            $this->logger->error('Console de la gestion : tenant introuvable', ['tenant' => $message->tenantCode, 'message' => $message->messageId]);

            return;
        }
        $this->emProvider->switchTenant((string) $tenant['dbname'], $message->tenantCode);
        $this->responder->answer($message->messageId);
    }
}
