<?php

namespace App\MessageHandler;

use App\Entity\Entreprise;
use App\Message\EntitlementChangedMessage;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\Messenger\WorkerContext;
use App\Services\Notification\NotificationCatalog;
use App\Services\Notification\NotificationGate;
use App\Services\OrderService\TaxEngine;
use App\Services\Platform\PlatformModeService;
use App\Services\Platform\TenantDatabaseConnector;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Courriel au contact du site quand un droit d'usage change (lot 3, 04/11/2026) : sujet et textes du domaine `emails`
 * (`entitlement.*`, fr et en), langue du site, gabarit `emails/entitlement_notice.html.twig`. Le site cible est lu en SQL direct
 * (TenantDatabaseConnector) et le courriel part par le serveur de la plateforme (repli : serveur du site courant).
 *
 * Lot NOTIF-2 (15/11/2026) : un courriel de droits urgent ou de suivi passe par la décision d'envoi (`NotificationGate`) DU SITE
 * CONCERNÉ : niveau du site et du destinataire, plafond, heures, journal des notifications de ce site. La décision lit le site
 * courant ; le gestionnaire s'y place donc d'abord (`enter`). Dans un worker (le cas de la production : transport `email`,
 * `WorkerContext`) il bascule la connexion vers le site concerné, puis la remet. Hors d'un worker (transport en direct : requête ou
 * commande d'un AUTRE site), il ne bascule jamais (un switchTenant y détacherait les entités de l'appelant) : le courriel part alors
 * comme avant les niveaux. Un droit acheté
 * (`entitlement_purchase`) et l'avis à la plateforme sont envoyés tels quels.
 * Destinataire : contact de la fiche Entreprise, sinon expéditeur configuré du site, sinon premier administrateur. Jamais relancé.
 */
#[AsMessageHandler]
final class EntitlementChangedHandler
{
    private const EVENTS_WITH_EMAIL = ['granted', 'trial_started', 'expiring_7', 'expiring_1', 'expired', 'grace', 'suspended', 'resumed', 'request_received', 'request_refused', 'request_new'];
    /** Événements adressés au super administrateur de la plateforme (compte présent sur chaque base) plutôt qu'au contact du site */
    private const EVENTS_TO_PLATFORM = ['request_new'];
    /** Événements urgents : paiement en attente, accès suspendu, accès terminé, fin d'essai demain ; les autres sont du suivi */
    private const EVENTS_URGENT = ['grace', 'suspended', 'expired', 'expiring_1'];
    /** Sources d'un droit payé : sa confirmation est un courriel indispensable (décision C du propriétaire) */
    private const PAID_SOURCES = ['subscription', 'purchase'];

    public function __construct(
        private readonly TenantDatabaseConnector $connector,
        private readonly EmailSenderService $mailer,
        private readonly TranslatorInterface $translator,
        private readonly PlatformModeService $platform,
        private readonly LoggerInterface $logger,
        private readonly \App\Services\TenantPublicHostResolver $hosts,
        private readonly ?NotificationGate $gate = null,
        private readonly ?TenantEntityManagerProvider $emProvider = null,
        private readonly ?TenantConnectionManager $tenants = null,
        private readonly ?WorkerContext $worker = null,
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
        // Lot MOD-1 (décision du chef) : AUCUNE clé n'est créée automatiquement (ce courriel de suivi peut être retenu). À l'octroi ou à
        // l'essai d'une APPLICATION, il dit où la créer : « Clés des applications » (clé et code QR par un courriel indispensable)
        if (in_array($event, ['granted', 'trial_started'], true) && isset(FeatureCatalog::GOVERNED_APP[$message->featureKey])) {
            $rows[$this->translator->trans('entitlement.row_app_key', [], 'emails', $language)] = $this->translator->trans('entitlement.app_key_create', [], 'emails', $language);
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
            // `platformSignature` : `signature` est écrasée par celle de la configuration d'envoi du site quand le courriel part par le
            // serveur du site (repli), et ce site est celui de la requête en cours, pas forcément celui du courriel
            'signature' => $this->translator->trans('entitlement.signature', [], 'emails', $language),
            'platformSignature' => $this->translator->trans('entitlement.signature', [], 'emails', $language),
            'locale' => $language, 'intlLocale' => $language === 'en' ? 'en_CA' : 'fr_CA',
        ];
        $subject = $this->mailer->subject("entitlement.subject_$event", $params, $language);
        // serveur de la plateforme d'abord ; sans compte expéditeur de plateforme (tests, poste de développement), serveur du site courant
        $send = fn (): bool => $this->mailer->sendPlatformEmail($to, $subject, 'emails/entitlement_notice.html.twig', $context)
            || $this->mailer->sendTemplatedEmail($to, $subject, 'emails/entitlement_notice.html.twig', $context, $language, $this->host($tenant));
        $kind = self::kind($event, $message->payload);
        $governed = in_array(NotificationCatalog::family($kind), [NotificationCatalog::URGENT, NotificationCatalog::FOLLOWUP], true);
        $leave = $governed ? $this->enter($tenant) : null;
        if ($leave === null) {
            $outcome = $send() ? NotificationGate::STATUS_SENT : NotificationGate::STATUS_FAILED;
        } else {
            try {
                $outcome = $this->gate->deliver($kind, $to, $subject, [], $send);
            } finally {
                $leave();
            }
        }
        $this->logger->info(sprintf('[Entitlements] courriel %s pour %s / %s : %s', $event, $message->tenantCode, $message->featureKey, $outcome));
    }

    /** Le type de courriel d'un événement de droits (`NotificationCatalog`) */
    public static function kind(string $event, array $payload): string
    {
        if (in_array($event, self::EVENTS_TO_PLATFORM, true)) {
            return 'platform_feature_request';
        }
        if ($event === 'granted' && in_array($payload['source'] ?? null, self::PAID_SOURCES, true)) {
            return 'entitlement_purchase';
        }

        return in_array($event, self::EVENTS_URGENT, true) ? NotificationCatalog::ENTITLEMENT_URGENT : NotificationCatalog::ENTITLEMENT_FOLLOWUP;
    }

    /**
     * Se place sur le site concerné pour que la décision d'envoi lise SES niveaux et écrive dans SON journal. Rend de quoi revenir au
     * site d'avant, ou null quand la décision ne peut pas être prise ici (pas de décision d'envoi, ou autre site hors d'un worker : on
     * ne bascule jamais la connexion d'un appelant en plein travail).
     */
    private function enter(array $tenant): ?\Closure
    {
        if ($this->gate === null || $this->emProvider === null || $this->tenants === null) {
            return null;
        }
        $current = (string) $this->tenants->getCurrentTenantCode();
        if ($current === (string) $tenant['code']) {
            return static function (): void {};
        }
        if ($this->worker?->inWorker() !== true) {
            return null;
        }
        try {
            // lu des paramètres, sans requête : une connexion tombée ne doit pas faire partir le courriel sans décision
            $previous = $current !== '' ? (string) ($this->emProvider->getEntityManager()->getConnection()->getParams()['dbname'] ?? '') : '';
            $this->emProvider->switchTenant((string) $tenant['dbname'], (string) $tenant['code']);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Entitlements] site %s : connexion impossible pour la décision d\'envoi (%s), le courriel part sans elle', $tenant['code'], $e::class));

            return null;
        }

        return function () use ($previous, $current): void {
            if ($previous === '') {
                return;
            }
            try {
                $this->emProvider->switchTenant($previous, $current);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('[Entitlements] retour au site %s impossible (%s)', $current, $e::class));
            }
        };
    }

    /** Hôte du site concerné, pour les adresses absolues du gabarit (avant le 15/11/2026 : le mot « emails », passé à la place d'un hôte) */
    private function host(array $tenant): string
    {
        try {
            return (string) ($this->hosts->describe((string) $tenant['code'])['host'] ?? '');
        } catch (\Throwable) {
            return '';
        }
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
