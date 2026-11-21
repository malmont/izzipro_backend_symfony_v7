<?php

namespace App\Services\SubscriptionService;

use App\Entity\Subscription;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\MediaUrlResolver;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Courriels des abonnements (échec de paiement, résiliation faute de paiement, pause, résiliation ; lot ABO-2 : séance annulée par la
 * boutique ; lot ABO-3a : prestation faite ; lot ABO-3b : message de la boutique dans le fil) : configuration d'envoi du site, jamais
 * bloquants. Lot ABO-LANG (21/11/2026, décision du chef) : dans la langue de l'ABONNÉ (`user.locale`), sinon celle du site, jamais celle
 * de la requête ; textes dans `translations/emails.{fr,en}.yaml` (`subscription_notice.*`).
 */
final class SubscriptionMailer
{
    public const KINDS = ['payment_failed', 'canceled_unpaid', 'paused', 'canceled', 'cancel_scheduled', 'cancel_at_term_end', 'session_cancelled', 'session_done', 'thread_message', 'session_reminder'];

    public function __construct(
        private readonly EmailSenderService $mailer,
        private readonly MediaUrlResolver $urls,
        private readonly LoggerInterface $logger,
        private readonly EmailLocale $locale,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** La langue des courriels de cet abonnement : celle de l'abonné, sinon celle du site */
    public function languageOf(Subscription $subscription): string
    {
        return $this->locale->forUser($subscription->getUser());
    }

    /** Un texte du domaine `emails` dans cette langue (valeur d'une ligne du récapitulatif) */
    public function text(string $key, string $language, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'emails', $language);
    }

    /**
     * @param value-of<self::KINDS> $kind
     * @param array{rows?: array<string, string>, payUrl?: ?string, ics?: ?string} $details lignes ajoutées au récapitulatif
     *        (libellé : une clé `subscription_notice.row.*`, traduite ici ; valeur déjà dans la langue de `languageOf`), lien de paiement ;
     *        lot ABO-4 : `ics`, la pièce d'agenda (`METHOD:PUBLISH`, une séance annulée en `STATUS:CANCELLED`)
     * @return bool parti (ou mis en file)
     */
    public function notify(Subscription $subscription, string $kind, string $host, array $details = []): bool
    {
        $email = $subscription->getUser()?->getEmail();
        if (!$email || !in_array($kind, self::KINDS, true)) {
            return false;
        }
        $language = $this->languageOf($subscription);
        $subject = $this->text('subscription_notice.' . $kind . '.subject', $language, ['%plan%' => $subscription->getPlan()?->getName($language) ?? '']);
        $rows = [];
        foreach ($details['rows'] ?? [] as $label => $value) {
            $rows[$this->text((string) $label, $language)] = $value;
        }
        $attachments = ($details['ics'] ?? null) !== null
            ? [['name' => 'seance.ics', 'body' => $details['ics'], 'contentType' => 'text/calendar; charset=utf-8; method=PUBLISH']] : [];
        try {
            return $this->mailer->sendTemplatedEmail($email, $subject, 'emails/subscription_notice.html.twig', [
                'subscription' => $subscription, 'kind' => $kind, 'title' => $subject,
                'message' => $this->text('subscription_notice.' . $kind . '.message', $language, ['%plan%' => $subscription->getPlan()?->getName($language) ?? '']),
                'planName' => $subscription->getPlan()?->getName($language),
                'rows' => $rows, 'payUrl' => $details['payUrl'] ?? null,
            ], $language, $this->urls->getEmailLogosBaseUrl($host !== '' ? $host : null) . '/', null, null, $attachments);
        } catch (\Throwable $e) {
            $this->logger->error('[Subscription] Courriel non envoyé : ' . $e->getMessage(), ['kind' => $kind, 'subscription' => $subscription->getId()]);

            return false;
        }
    }
}
