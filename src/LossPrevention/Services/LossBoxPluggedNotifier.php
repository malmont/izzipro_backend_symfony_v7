<?php

namespace App\LossPrevention\Services;

use App\Gestion\Services\GestionNotificationService;
use App\LossPrevention\Master\LossPairingRepository;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\Site\AdminSpaceUrl;
use App\Services\Site\SiteName;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Votre boîtier est branché » (lot L6-b, `docs/prevention-pertes.md` § 19.5), dans le site acheteur (le worker des courriels a changé
 * de site) : une notification sur le téléphone (type `loss_box_plugged`, INDISPENSABLE) et un courriel (`NotificationCatalog`,
 * `loss_box_plugged`, INDISPENSABLE : jamais retenu), à chaque administrateur du site, jamais au compte de la plateforme. Le courriel
 * part par l'expéditeur de la PLATEFORME : le boîtier vient d'elle, et le site n'a peut-être pas d'envoi à lui. Il porte le numéro de
 * série, le lien vers l'écran « Boîtiers » du site et la consigne « si vous n'avez rien branché, ne confirmez pas » ; jamais le code.
 */
class LossBoxPluggedNotifier
{
    private const TEMPLATE = 'emails/support_agent_invitation.html.twig'; // même forme : un message, un bouton

    public function __construct(
        private readonly LossPairingRepository $pairings,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionProvider $tenant,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly ?GestionNotificationService $notifications = null,
        private readonly ?EmailSenderService $mailer = null,
        private readonly ?EmailLocale $locale = null,
        private readonly ?AdminSpaceUrl $adminSpace = null,
        private readonly ?SiteName $siteName = null,
        private readonly ?\App\Services\FeatureAccess\ModuleAccessService $modules = null,
        private readonly ?\App\Services\Platform\PlatformModeService $platform = null,
    ) {
    }

    /**
     * L'expédition d'un boîtier branché a été annulée par la plateforme : une ligne au journal du module de CE site, et chaque administrateur
     * est prévenu (notification et courriel indispensables), sans le code. Rien si l'expédition n'est pas de ce site ou pas annulée.
     *
     * @return array{push: int, email: int}
     */
    public function cancelled(int $shipmentId): array
    {
        $site = (string) $this->tenant->getTenantCode();
        $shipment = $this->pairings->shipment($shipmentId);
        if ($shipment === null || !hash_equals((string) $shipment['tenant_code'], $site) || $shipment['status'] !== LossPairingRepository::SHIPMENT_CANCELLED) {
            return ['push' => 0, 'email' => 0];
        }
        $journal = $this->emProvider->getEntityManager()->getRepository(\App\LossPrevention\Entity\LossJournalEntry::class);
        \assert($journal instanceof \App\LossPrevention\Repository\LossJournalRepository);
        $journal->append(\App\LossPrevention\Entity\LossJournalEntry::BOX_SHIPMENT_CANCELLED, null, null, ['shipment' => $shipmentId, 'serial' => (string) $shipment['serial']]);

        return $this->send($shipmentId, (string) $shipment['serial'], 'loss_box_cancelled', 'cancelled');
    }

    /** @return array{push: int, email: int} combien de notifications et de courriels sont partis */
    public function notify(int $shipmentId): array
    {
        $site = (string) $this->tenant->getTenantCode();
        $shipment = $this->pairings->shipment($shipmentId);
        // l'avis n'est que pour une expédition de CE site, encore branchée (une confirmation ou une annulation est passée avant : rien)
        if ($shipment === null || !hash_equals((string) $shipment['tenant_code'], $site) || $shipment['status'] !== LossPairingRepository::SHIPMENT_PLUGGED) {
            return ['push' => 0, 'email' => 0];
        }
        // un site qui a perdu le droit du module ne reçoit pas d'avis (sa confirmation lui est fermée) ; le boîtier attend (relecture, M9)
        if ($this->modules !== null && !($this->platform?->isDemo() ?? false) && !$this->modules->isEnabled(\App\Services\FeatureAccess\ModuleAccessService::LOSS_PREVENTION)) {
            return ['push' => 0, 'email' => 0];
        }
        return $this->send($shipmentId, (string) $shipment['serial'], 'loss_box_plugged', '');
    }

    /** @return array{push: int, email: int} */
    private function send(int $shipmentId, string $serial, string $mail, string $textVariant): array
    {
        $site = (string) $this->tenant->getTenantCode();
        $admins = $this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT id, email FROM "user" WHERE roles::text LIKE \'%"ROLE_ADMIN"%\' AND roles::text NOT LIKE \'%"ROLE_SUPER_ADMIN"%\' AND deleted_at IS NULL AND is_verified = true ORDER BY id');
        $language = $this->locale?->language(null) ?? 'fr';
        $params = ['%serial%' => $serial, '%site%' => $this->siteName?->name() ?? $site];
        $t = fn (string $key): string => $this->translator->trans($mail . '.' . $key, $params, 'emails', $language);
        $link = $this->adminSpace?->onSite($site, 'pertes.boitiers');
        $push = 0;
        $email = 0;
        foreach ($admins as $admin) {
            $status = $this->notifications?->notify((int) $admin['id'], GestionNotificationService::LOSS_BOX_PLUGGED, ['%serial%' => $serial],
                ['screen' => 'pertes.boitiers', 'params' => new \stdClass()], ['type' => 'loss_box_shipment', 'id' => $shipmentId], null, false, $textVariant);
            $push += $status !== null ? 1 : 0;
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
                $this->logger->error('Prévention des pertes : courriel « ' . $mail . ' » en échec (' . $e::class . ').');
            }
        }

        return ['push' => $push, 'email' => $email];
    }
}
