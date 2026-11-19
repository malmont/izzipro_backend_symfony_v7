<?php

namespace App\Services\Group;

use App\Services\EmailConfigurationService\EmailSenderService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Lot G-1d : les courriels du lien « même entreprise », INDISPENSABLES (`NotificationCatalog`, clé `group_same_company`), aux administrateurs
 * vérifiés du site concerné (jamais au compte de la plateforme), par l'expéditeur de la plateforme, SANS lien d'action : on répond dans
 * l'écran Multi-boutiques. Sortes : `requested` (à la boutique), `confirmed` et `refused` (au siège), `removed_by_shop` (au siège),
 * `removed_by_head` (à la boutique), `set_at_accept` (au siège : la case cochée à l'acceptation de l'invitation). Un échec d'envoi n'arrête jamais le geste.
 */
class GroupCompanyMailer
{
    public const KINDS = ['requested', 'confirmed', 'refused', 'removed_by_shop', 'removed_by_head', 'set_at_accept'];
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
        $t = fn (string $key): string => $this->translator->trans('group_same_company.' . $kind . '.' . $key, $params, 'emails', $language);
        $sent = 0;
        try {
            $recipients = $this->invitations->recipients($dbname);
        } catch (\Throwable $e) {
            $this->logger->error('Groupe : destinataires du lien « même entreprise » illisibles (' . $e::class . ').');

            return 0;
        }
        foreach ($recipients as $email) {
            try {
                $sent += $this->mailer->sendPlatformEmail($email, $t('subject'), self::TEMPLATE, ['title' => $t('title'), 'greeting' => $t('greeting'), 'message' => $t('message'),
                    'button' => '', 'link' => '', 'validity' => $t('validity'), 'ignore' => $t('ignore'), 'siteLine' => (string) ($params['%site%'] ?? '')]) ? 1 : 0;
            } catch (\Throwable $e) {
                $this->logger->error('Groupe : courriel du lien « même entreprise » en échec (' . $e::class . ').');
            }
        }

        return $sent;
    }
}
