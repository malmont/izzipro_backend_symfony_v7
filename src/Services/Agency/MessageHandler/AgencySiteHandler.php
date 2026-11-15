<?php

namespace App\Services\Agency\MessageHandler;

use App\Services\Agency\AgencySiteRequestService;
use App\Services\Agency\Message\BuildAgencySiteMessage;
use App\Services\Agency\Message\ResendAgencySiteInvitationMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Création d'un site par une agence (lot AG-2) : fabrique le site d'une demande, ou renvoie l'invitation de son administrateur.
 * Jamais relancé : une demande qui échoue le dit par son état ; une fabrication interrompue est passée « en échec » par le passage
 * planifié (`app:agency:sites:build`).
 */
final class AgencySiteHandler
{
    public function __construct(private readonly AgencySiteRequestService $requests, private readonly LoggerInterface $logger)
    {
    }

    #[AsMessageHandler]
    public function build(BuildAgencySiteMessage $message): void
    {
        try {
            $this->requests->process($message->requestId);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Agences] demande de site %d : fabrication en échec (%s)', $message->requestId, $e::class), ['exception' => $e]);
        }
    }

    #[AsMessageHandler]
    public function resend(ResendAgencySiteInvitationMessage $message): void
    {
        try {
            $this->requests->resendInvitation($message->requestId, $message->by);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Agences] demande de site %d : invitation non renvoyée (%s)', $message->requestId, $e::class), ['exception' => $e]);
        }
    }
}
