<?php

namespace App\Services\Group;

use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\Platform\PlatformAccessJournal;
use App\Services\Platform\TenantDatabaseConnector;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Le courriel d'invitation à rejoindre un groupe (lot G-1a), aux ADMINISTRATEURS du site invité (jamais au compte de la plateforme), par
 * l'expéditeur de la plateforme. Il dit en clair le consentement demandé (`GroupService::consent`). Classé indispensable (`NotificationCatalog`).
 */
class GroupInvitationMailer
{
    private const TEMPLATE = 'emails/support_agent_invitation.html.twig'; // même forme : un message, un bouton

    public function __construct(
        private readonly EmailSenderService $mailer,
        private readonly TranslatorInterface $translator,
        private readonly TenantDatabaseConnector $databases,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return int le nombre de courriels partis */
    public function notify(string $dbname, string $siteName, string $groupName, string $headName, int $days, string $language): int
    {
        $params = ['%site%' => $siteName, '%group%' => $groupName, '%head%' => $headName, '%days%' => (string) $days, '%consent%' => GroupService::consent($groupName, $language)];
        $t = fn (string $key) => $this->translator->trans('group_invitation.' . $key, $params, 'emails', $language);
        $sent = 0;
        foreach ($this->recipients($dbname) as $email) {
            try {
                $sent += $this->mailer->sendPlatformEmail($email, $t('subject'), self::TEMPLATE, ['title' => $t('title'), 'greeting' => $t('greeting'), 'message' => $t('message'),
                    'button' => '', 'link' => '', 'validity' => $t('validity'), 'ignore' => $t('ignore'), 'siteLine' => $siteName]) ? 1 : 0;
            } catch (\Throwable $e) {
                $this->logger->error('Groupe : courriel d\'invitation en échec (' . $e::class . ').');
            }
        }

        return $sent;
    }

    /** @return list<string> les administrateurs vérifiés du site, hors compte de la plateforme (partagé avec le lien « même entreprise », lot G-1d) */
    public function recipients(string $dbname): array
    {
        $rows = $this->databases->pdo($dbname)->query("SELECT email, roles FROM \"user\" WHERE is_verified = TRUE AND roles::text LIKE '%ROLE_ADMIN%' ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $roles = json_decode((string) $row['roles'], true);
            if (is_array($roles) && in_array('ROLE_ADMIN', $roles, true) && !PlatformAccessJournal::isPlatformAccount($roles) && filter_var((string) $row['email'], \FILTER_VALIDATE_EMAIL) !== false) {
                $out[] = (string) $row['email'];
            }
        }

        return array_values(array_unique($out));
    }
}
