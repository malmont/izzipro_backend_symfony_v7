<?php

namespace App\MessageHandler;

use App\Entity\Entreprise;
use App\Message\EntitlementChangedMessage;
use App\Services\CaisseService\SiteLocaleResolver;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Courriel au contact du site quand un droit d'usage change (lot 3, 04/11/2026) : sujet et textes du domaine `emails`
 * (`entitlement.*`, fr et en), langue du site, gabarit `emails/entitlement_notice.html.twig`, serveur du site puis repli sur celui
 * de la plateforme. Bascule sur la base du site si elle n'est pas la base courante. Jamais relancé : un échec est journalisé.
 */
#[AsMessageHandler]
final class EntitlementChangedHandler
{
    private const EVENTS_WITH_EMAIL = ['granted', 'trial_started', 'expiring_7', 'expiring_1', 'expired', 'grace', 'suspended', 'resumed'];

    public function __construct(
        private readonly TenantConnectionManager $tenants,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly EmailSenderService $mailer,
        private readonly SiteLocaleResolver $siteLocale,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(EntitlementChangedMessage $message): void
    {
        if (!in_array($message->event, self::EVENTS_WITH_EMAIL, true)) {
            return;
        }
        $tenant = $this->tenants->findTenantByCode($message->tenantCode);
        if (!is_array($tenant) || empty($tenant['dbname'])) {
            $this->logger->warning(sprintf('[Entitlements] courriel %s : site %s introuvable', $message->event, $message->tenantCode));

            return;
        }
        if ($this->tenants->getCurrentTenantCode() !== $message->tenantCode) {
            $this->emProvider->switchTenant((string) $tenant['dbname'], $message->tenantCode);
        }
        $em = $this->emProvider->getEntityManager();
        $entreprise = $em->getRepository(Entreprise::class)->findOneBy([], ['id' => 'ASC']);
        $to = $this->recipient($entreprise);
        if ($to === null) {
            $this->logger->warning(sprintf('[Entitlements] courriel %s : aucun contact sur le site %s', $message->event, $message->tenantCode));

            return;
        }
        $language = $this->siteLocale->siteLocale();
        $feature = FeatureCatalog::label($message->featureKey, $language);
        $endsAt = isset($message->payload['endsAt']) && $message->payload['endsAt'] ? new \DateTimeImmutable((string) $message->payload['endsAt']) : null;
        $graceUntil = isset($message->payload['graceUntil']) && $message->payload['graceUntil'] ? new \DateTimeImmutable((string) $message->payload['graceUntil']) : null;
        $date = ($endsAt ?? $graceUntil)?->format($language === 'en' ? 'Y-m-d' : 'd/m/Y') ?? '';
        $params = ['%feature%' => $feature, '%date%' => $date, '%days%' => (string) ($message->payload['days'] ?? ''), '%site%' => (string) $entreprise->getName()];
        $event = $message->event;
        $rows = [$this->translator->trans('entitlement.row_feature', [], 'emails', $language) => $feature];
        if ($date !== '') {
            $rows[$this->translator->trans($event === 'grace' ? 'entitlement.row_grace_until' : 'entitlement.row_ends_at', [], 'emails', $language)] = $date;
        }
        $sent = $this->mailer->sendCustomerEmail($to, "entitlement.subject_$event", $params, 'emails/entitlement_notice.html.twig', [
            'title' => $this->translator->trans("entitlement.title_$event", $params, 'emails', $language),
            'greeting' => $this->translator->trans('entitlement.greeting', $params, 'emails', $language),
            'message' => $this->translator->trans("entitlement.message_$event", $params, 'emails', $language),
            'nextStep' => $this->translator->trans("entitlement.next_$event", $params, 'emails', $language),
            'rows' => $rows,
            'signature' => $this->translator->trans('entitlement.signature', [], 'emails', $language),
        ], $language, 'emails', "entitlement.subject_$event");
        $this->logger->info(sprintf('[Entitlements] courriel %s pour %s / %s : %s', $event, $message->tenantCode, $message->featureKey, $sent ? 'envoyé' : 'non envoyé'));
    }

    /** Contact du site (fiche Entreprise), sinon l'expéditeur configuré du site, sinon le premier administrateur ; null si rien */
    private function recipient(?Entreprise $entreprise): ?string
    {
        $email = trim((string) $entreprise?->getEmail());
        if ($email !== '') {
            return $email;
        }
        $conn = $this->emProvider->getEntityManager()->getConnection();
        try {
            $from = $conn->fetchOne('SELECT from_email FROM email_configuration ORDER BY id LIMIT 1');
            if (is_string($from) && trim($from) !== '') {
                return trim($from);
            }
            $admin = $conn->fetchOne("SELECT email FROM \"user\" WHERE roles::text LIKE '%ROLE_ADMIN%' AND roles::text NOT LIKE '%ROLE_SUPER_ADMIN%' ORDER BY id LIMIT 1");
            if (is_string($admin) && trim($admin) !== '') {
                return trim($admin);
            }
        } catch (\Throwable) {
        }

        return null;
    }
}
