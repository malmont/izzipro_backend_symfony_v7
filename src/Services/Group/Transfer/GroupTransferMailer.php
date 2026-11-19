<?php

namespace App\Services\Group\Transfer;

use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\Group\GroupInvitationMailer;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Lot G-5 : les courriels du transfert entre boutiques, INDISPENSABLES (`NotificationCatalog`, clé `group_transfer`), aux administrateurs
 * vérifiés du site concerné (jamais au compte de la plateforme), par l'expéditeur de la plateforme, SANS lien d'action : on répond dans
 * l'écran des transferts. Sortes : `requested` (à la SOURCE : un transfert à envoyer), `sent` (à la DESTINATION : une commande en route),
 * `send_failed` (au SIÈGE : l'envoi n'a pas abouti, stock rendu). Un échec d'envoi n'arrête jamais le geste.
 */
class GroupTransferMailer
{
    public const KINDS = ['requested', 'sent', 'send_failed'];
    private const TEMPLATE = 'emails/support_agent_invitation.html.twig'; // même forme : un message, sans bouton

    public function __construct(
        private readonly EmailSenderService $mailer,
        private readonly TranslatorInterface $translator,
        private readonly GroupInvitationMailer $invitations,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param array<string, string> $params @return int le nombre de courriels partis */
    public function send(?string $dbname, string $kind, array $params, string $language): int
    {
        if ($dbname === null || !in_array($kind, self::KINDS, true)) {
            return 0;
        }
        $language = $language === 'en' ? 'en' : 'fr';
        $t = fn (string $key): string => $this->translator->trans('group_transfer.' . $kind . '.' . $key, $params, 'emails', $language);
        $sent = 0;
        try {
            $recipients = $this->invitations->recipients($dbname);
        } catch (\Throwable $e) {
            $this->logger->error('Groupe : destinataires du transfert illisibles (' . $e::class . ').');

            return 0;
        }
        foreach ($recipients as $email) {
            try {
                $sent += $this->mailer->sendPlatformEmail($email, $t('subject'), self::TEMPLATE, ['title' => $t('title'), 'greeting' => $t('greeting'), 'message' => $t('message'),
                    'button' => '', 'link' => '', 'validity' => $t('validity'), 'ignore' => $t('ignore'), 'siteLine' => (string) ($params['%site%'] ?? '')]) ? 1 : 0;
            } catch (\Throwable $e) {
                $this->logger->error('Groupe : courriel de transfert en échec (' . $e::class . ').');
            }
        }

        return $sent;
    }
}
