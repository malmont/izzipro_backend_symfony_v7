<?php

namespace App\Services\SubscriptionService;

use App\Entity\Subscription;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\MediaUrlResolver;
use Psr\Log\LoggerInterface;

/** Courriels des abonnements (échec de paiement, pause, résiliation) : configuration d'envoi du site, jamais bloquants */
final class SubscriptionMailer
{
    private const TEXTS = [
        'payment_failed' => ['Paiement de votre abonnement refusé', 'Le paiement de votre abonnement « %s » n\'a pas abouti. Mettez votre carte à jour depuis votre compte (portail de paiement) : sans paiement, l\'abonnement sera suspendu.'],
        'paused' => ['Votre abonnement est en pause', 'Votre abonnement « %s » est en pause : aucune échéance ne sera facturée jusqu\'à sa reprise depuis votre compte.'],
        'canceled' => ['Votre abonnement est résilié', 'Votre abonnement « %s » est résilié. Merci de votre confiance ; vous pouvez souscrire de nouveau à tout moment.'],
        'cancel_scheduled' => ['Votre abonnement prendra fin', 'Votre abonnement « %s » prendra fin à la fin de la période en cours. Vous pouvez le reprendre avant cette date depuis votre compte.'],
    ];

    public function __construct(
        private readonly EmailSenderService $mailer,
        private readonly MediaUrlResolver $urls,
        private readonly LoggerInterface $logger
    ) {
    }

    /** @param 'payment_failed'|'paused'|'canceled'|'cancel_scheduled' $kind */
    public function notify(Subscription $subscription, string $kind, string $locale, string $host): void
    {
        $email = $subscription->getUser()?->getEmail();
        if (!$email || !isset(self::TEXTS[$kind])) {
            return;
        }
        [$subject, $message] = self::TEXTS[$kind];
        try {
            $this->mailer->sendTemplatedEmail($email, $subject, 'emails/subscription_notice.html.twig', [
                'subscription' => $subscription, 'kind' => $kind, 'title' => $subject,
                'message' => sprintf($message, $subscription->getPlan()?->getName($locale) ?? ''),
            ], $locale, $this->urls->getEmailLogosBaseUrl($host) . '/');
        } catch (\Throwable $e) {
            $this->logger->error('[Subscription] Courriel non envoyé : ' . $e->getMessage(), ['kind' => $kind, 'subscription' => $subscription->getId()]);
        }
    }
}
