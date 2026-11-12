<?php

namespace App\Support\Services;

use App\Services\EmailConfigurationService\EmailSenderService;
use App\Support\Entity\SupportMessage;
use App\Support\Entity\SupportTicket;
use Psr\Log\LoggerInterface;

/**
 * Réponse de l'agent envoyée au client (lot 1), dans la langue du client (gabarit `emails/support_reply.html.twig`, domaine `emails`).
 * Mode `log` des réglages (défaut tant que les applications ne gèrent pas l'assistance) : aucun envoi, une ligne de journal sans adresse ni
 * texte ; mode `send` : envoi par le serveur du site, sinon celui de la plateforme. Rend sent | failed | logged.
 */
class SupportMailer
{
    public function __construct(private readonly EmailSenderService $mailer, private readonly LoggerInterface $logger)
    {
    }

    public function reply(SupportTicket $ticket, SupportMessage $message, string $mode, string $host): string
    {
        if ($ticket->getCustomerEmail() === null || $ticket->getCustomerEmail() === '') {
            return 'failed';
        }
        if ($mode !== 'send') {
            $this->logger->info(sprintf('[Assistance] réponse au ticket %s non envoyée (mode journal), message %d', $ticket->getReference(), (int) $message->getId()));

            return 'logged';
        }
        try {
            $sent = $this->mailer->sendCustomerEmail($ticket->getCustomerEmail(), 'support_reply.subject', ['%reference%' => $ticket->getReference(), '%subject%' => $ticket->getSubject()],
                'emails/support_reply.html.twig', ['ticket' => ['reference' => $ticket->getReference(), 'subject' => $ticket->getSubject()], 'customerName' => $ticket->getCustomerName(), 'body' => $message->getBody()],
                $ticket->getCustomerLocale(), $host, 'support_reply.subject');
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Assistance] réponse au ticket %s non envoyée : %s', $ticket->getReference(), $e->getMessage()));
            $sent = false;
        }

        return $sent ? 'sent' : 'failed';
    }
}
