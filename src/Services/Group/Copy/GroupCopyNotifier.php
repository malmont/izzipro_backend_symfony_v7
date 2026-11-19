<?php

namespace App\Services\Group\Copy;

use App\Gestion\Services\GestionNotificationService;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\Site\AdminSpaceUrl;
use App\Services\Site\SiteName;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Lot G-4 (ajout 3 du chef) : à la confirmation d'une copie, chaque administrateur de la boutique CIBLE (site courant) reçoit une notification
 * de la gestion et un courriel, INDISPENSABLES (l'écriture d'un tiers dans son catalogue) : « Le directeur du groupe X a copié N articles
 * dans votre catalogue (masqués) ; annulation possible 7 jours ». Jamais au compte de la plateforme. Un échec d'envoi n'arrête pas la copie.
 */
class GroupCopyNotifier
{
    private const TEMPLATE = 'emails/support_agent_invitation.html.twig'; // même forme : un message, un bouton
    private const MAIL = 'group_catalog_copied';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionProvider $tenant,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly ?GestionNotificationService $notifications = null,
        private readonly ?EmailSenderService $mailer = null,
        private readonly ?EmailLocale $locale = null,
        private readonly ?AdminSpaceUrl $adminSpace = null,
        private readonly ?SiteName $siteName = null,
    ) {
    }

    /** @return array{push: int, email: int} */
    public function copied(int $copyId, string $groupName, int $count): array
    {
        $site = (string) $this->tenant->getTenantCode();
        $admins = $this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT id, email FROM "user" WHERE roles::text LIKE \'%"ROLE_ADMIN"%\' AND roles::text NOT LIKE \'%"ROLE_SUPER_ADMIN"%\' AND deleted_at IS NULL AND is_verified = true ORDER BY id');
        $language = $this->locale?->language(null) ?? 'fr';
        $params = ['%group%' => $groupName, '%count%' => (string) $count, '%site%' => $this->siteName?->name() ?? $site];
        $t = fn (string $key): string => $this->translator->trans(self::MAIL . '.' . $key, $params, 'emails', $language);
        $link = $this->adminSpace?->onSite($site, 'imports');
        $push = 0;
        $email = 0;
        foreach ($admins as $admin) {
            try {
                $status = $this->notifications?->notify((int) $admin['id'], GestionNotificationService::GROUP_CATALOG_COPIED, ['%group%' => $groupName, '%count%' => (string) $count],
                    ['screen' => 'imports', 'params' => new \stdClass()], ['type' => 'group_copy', 'id' => $copyId]);
                $push += $status !== null ? 1 : 0;
            } catch (\Throwable $e) {
                $this->logger->error('Groupe : notification de copie en échec (' . $e::class . ').');
            }
            $to = (string) $admin['email'];
            if ($this->mailer === null || filter_var($to, \FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            try {
                $email += $this->mailer->sendPlatformEmail($to, $t('subject'), self::TEMPLATE, [
                    'title' => $t('title'), 'greeting' => $t('greeting'), 'message' => $t('message'), 'button' => $link !== null ? $t('button') : '',
                    'link' => (string) $link, 'validity' => $t('validity'), 'ignore' => $t('ignore'), 'siteLine' => $params['%site%'],
                ]) ? 1 : 0;
            } catch (\Throwable $e) {
                // jamais le message de l'exception (il peut porter une adresse) : sa classe suffit
                $this->logger->error('Groupe : courriel de copie en échec (' . $e::class . ').');
            }
        }

        return ['push' => $push, 'email' => $email];
    }
}
