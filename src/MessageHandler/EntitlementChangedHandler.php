<?php

namespace App\MessageHandler;

use App\Entity\Entreprise;
use App\Message\EntitlementChangedMessage;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\OrderService\TaxEngine;
use App\Services\Platform\PlatformModeService;
use App\Services\Platform\TenantDatabaseConnector;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Courriel au contact du site quand un droit d'usage change (lot 3, 04/11/2026) : sujet et textes du domaine `emails`
 * (`entitlement.*`, fr et en), langue du site, gabarit `emails/entitlement_notice.html.twig`. Le site cible est lu en SQL direct
 * (TenantDatabaseConnector) et le courriel part par le serveur de la plateforme (repli : serveur du site courant) : la connexion du site courant n'est jamais
 * basculée (le message peut être traité dans la requête d'un autre site, et un switchTenant y détacherait ses entités).
 * Destinataire : contact de la fiche Entreprise, sinon expéditeur configuré du site, sinon premier administrateur. Jamais relancé.
 */
#[AsMessageHandler]
final class EntitlementChangedHandler
{
    private const EVENTS_WITH_EMAIL = ['granted', 'trial_started', 'expiring_7', 'expiring_1', 'expired', 'grace', 'suspended', 'resumed', 'request_received', 'request_refused', 'request_new'];
    /** Événements adressés au super administrateur de la plateforme (compte présent sur chaque base) plutôt qu'au contact du site */
    private const EVENTS_TO_PLATFORM = ['request_new'];

    public function __construct(
        private readonly TenantDatabaseConnector $connector,
        private readonly EmailSenderService $mailer,
        private readonly TranslatorInterface $translator,
        private readonly PlatformModeService $platform,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(EntitlementChangedMessage $message): void
    {
        if (!in_array($message->event, self::EVENTS_WITH_EMAIL, true)) {
            return;
        }
        $tenant = $this->connector->tenant($message->tenantCode);
        if ($tenant === null) {
            $this->logger->warning(sprintf('[Entitlements] courriel %s : site %s introuvable', $message->event, $message->tenantCode));

            return;
        }
        try {
            $pdo = $this->connector->pdo($tenant['dbname']);
            $site = $pdo->query('SELECT e.email, e.name, e.locale, a.country, a.state FROM entreprise e LEFT JOIN address_entreprise a ON a.entreprise_id = e.id ORDER BY e.id LIMIT 1')->fetch(\PDO::FETCH_ASSOC) ?: [];
            $to = in_array($message->event, self::EVENTS_TO_PLATFORM, true) ? $this->platformRecipient($pdo) : $this->recipient($pdo, $site);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Entitlements] courriel %s : site %s illisible : %s', $message->event, $message->tenantCode, $e->getMessage()));

            return;
        }
        if ($to === null) {
            $this->logger->warning(sprintf('[Entitlements] courriel %s : aucun contact sur le site %s', $message->event, $message->tenantCode));

            return;
        }
        $language = in_array($message->event, self::EVENTS_TO_PLATFORM, true) ? 'fr' : $this->language($site);
        $feature = FeatureCatalog::label($message->featureKey, $language);
        $endsAt = isset($message->payload['endsAt']) && $message->payload['endsAt'] ? new \DateTimeImmutable((string) $message->payload['endsAt']) : null;
        $graceUntil = isset($message->payload['graceUntil']) && $message->payload['graceUntil'] ? new \DateTimeImmutable((string) $message->payload['graceUntil']) : null;
        $date = ($endsAt ?? $graceUntil)?->format($language === 'en' ? 'Y-m-d' : 'd/m/Y') ?? '';
        $shopUrl = $this->platform->shopUrl();
        $params = ['%feature%' => $feature, '%date%' => $date, '%days%' => (string) ($message->payload['days'] ?? ''), '%site%' => (string) ($site['name'] ?? $tenant['name']), '%code%' => $tenant['code'], '%by%' => (string) ($message->payload['by'] ?? ''), '%note%' => (string) ($message->payload['message'] ?? $message->payload['note'] ?? ''), '%shop%' => (string) ($shopUrl ?? '')];
        $event = $message->event;
        $rows = [$this->translator->trans('entitlement.row_feature', [], 'emails', $language) => $feature];
        if ($date !== '') {
            $rows[$this->translator->trans($event === 'grace' ? 'entitlement.row_grace_until' : 'entitlement.row_ends_at', [], 'emails', $language)] = $date;
        }
        if (str_starts_with($event, 'request_')) {
            $rows[$this->translator->trans('entitlement.row_site', [], 'emails', $language)] = $params['%site%'] . ' (' . $tenant['code'] . ')';
            if ($params['%note%'] !== '') {
                $rows[$this->translator->trans('entitlement.row_message', [], 'emails', $language)] = $params['%note%'];
            }
        }
        $nextKey = "entitlement.next_$event";
        if ($shopUrl === null && in_array($event, ['request_received', 'request_refused', 'expired', 'expiring_7', 'expiring_1', 'trial_started', 'suspended'], true)) {
            $nextKey = "entitlement.next_{$event}_noshop"; // sans boutique de la plateforme : pas d'invitation à souscrire
        }
        $context = [
            'title' => $this->translator->trans("entitlement.title_$event", $params, 'emails', $language),
            'greeting' => $this->translator->trans('entitlement.greeting', $params, 'emails', $language),
            'message' => $this->translator->trans("entitlement.message_$event", $params, 'emails', $language),
            'nextStep' => $this->translator->trans($nextKey, $params, 'emails', $language),
            'rows' => $rows,
            'signature' => $this->translator->trans('entitlement.signature', [], 'emails', $language),
            'locale' => $language, 'intlLocale' => $language === 'en' ? 'en_CA' : 'fr_CA',
        ];
        $subject = $this->mailer->subject("entitlement.subject_$event", $params, $language);
        // serveur de la plateforme d'abord ; sans compte expéditeur de plateforme (tests, poste de développement), serveur du site courant
        $sent = $this->mailer->sendPlatformEmail($to, $subject, 'emails/entitlement_notice.html.twig', $context)
            || $this->mailer->sendTemplatedEmail($to, $subject, 'emails/entitlement_notice.html.twig', $context, $language, 'emails');
        $this->logger->info(sprintf('[Entitlements] courriel %s pour %s / %s : %s', $event, $message->tenantCode, $message->featureKey, $sent ? 'envoyé' : 'non envoyé'));
    }

    /** Contact du site (fiche Entreprise), sinon l'expéditeur configuré du site, sinon le premier administrateur ; null si rien */
    private function recipient(\PDO $pdo, array $site): ?string
    {
        $email = trim((string) ($site['email'] ?? ''));
        if ($email !== '') {
            return $email;
        }
        try {
            $from = $pdo->query('SELECT from_email FROM email_configuration ORDER BY id LIMIT 1')->fetchColumn();
            if (is_string($from) && trim($from) !== '') {
                return trim($from);
            }
            $admin = $pdo->query("SELECT email FROM \"user\" WHERE roles::text LIKE '%ROLE_ADMIN%' AND roles::text NOT LIKE '%ROLE_SUPER_ADMIN%' ORDER BY id LIMIT 1")->fetchColumn();
            if (is_string($admin) && trim($admin) !== '') {
                return trim($admin);
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /** Super administrateur de la plateforme : son compte existe sur chaque base de site (SuperAdminAccountService) */
    private function platformRecipient(\PDO $pdo): ?string
    {
        try {
            $email = $pdo->query("SELECT email FROM \"user\" WHERE roles::text LIKE '%ROLE_SUPER_ADMIN%' ORDER BY id LIMIT 1")->fetchColumn();

            return is_string($email) && trim($email) !== '' ? trim($email) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Langue du site : choisie (`entreprise.locale`), sinon la règle de SiteLocaleResolver d'après l'adresse */
    private function language(array $site): string
    {
        $explicit = $site['locale'] ?? null;
        if (is_string($explicit) && in_array($explicit, Entreprise::LOCALES, true)) {
            return $explicit;
        }
        $address = TaxEngine::normalizeAddress(['country' => $site['country'] ?? null, 'province' => $site['state'] ?? null]);

        return match ($address['country'] ?? null) {
            'CA' => in_array($address['province'] ?? null, ['QC', null], true) ? EmailLocale::FRENCH : EmailLocale::ENGLISH,
            'US' => EmailLocale::ENGLISH,
            default => EmailLocale::FRENCH,
        };
    }
}
