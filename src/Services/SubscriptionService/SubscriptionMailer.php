<?php

namespace App\Services\SubscriptionService;

use App\Entity\Subscription;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\MediaUrlResolver;
use Psr\Log\LoggerInterface;

/** Courriels des abonnements (échec de paiement, résiliation faute de paiement, pause, résiliation ; lot ABO-2 : séance annulée par la boutique) : configuration d'envoi du site, jamais bloquants */
final class SubscriptionMailer
{
    private const TEXTS = [
        'payment_failed' => ['Paiement de votre abonnement refusé', 'Le paiement de votre abonnement « %s » n\'a pas abouti. Payez l\'échéance avec le bouton ci-dessous ou mettez votre carte à jour depuis votre compte : sans paiement, l\'abonnement sera résilié après le dernier essai.'],
        'canceled_unpaid' => ['Votre abonnement est résilié faute de paiement', 'Après plusieurs essais, le paiement de votre abonnement « %s » n\'a pas abouti : l\'abonnement est résilié. Vous pouvez souscrire de nouveau à tout moment depuis le site.'],
        'paused' => ['Votre abonnement est en pause', 'Votre abonnement « %s » est en pause : aucune échéance ne sera facturée jusqu\'à sa reprise depuis votre compte.'],
        'canceled' => ['Votre abonnement est résilié', 'Votre abonnement « %s » est résilié. Merci de votre confiance ; vous pouvez souscrire de nouveau à tout moment.'],
        'cancel_scheduled' => ['Votre abonnement prendra fin', 'Votre abonnement « %s » prendra fin à la fin de la période en cours. Vous pouvez le reprendre avant cette date depuis votre compte.'],
        // lot ABO-1 : résiliation programmée à la fin d'un engagement minimal (la date est dans le récapitulatif)
        'cancel_at_term_end' => ['Votre résiliation est enregistrée', 'Votre demande de résiliation de l\'abonnement « %s » est enregistrée. Il prendra fin à la fin de votre engagement, à la date ci-dessous ; aucune échéance ne sera prélevée après. Vous pouvez annuler cette résiliation avant cette date depuis votre compte.'],
        // lot ABO-2 (Q2) : un créneau annulé par la boutique ; le droit redevient à planifier, rien n'est perdu
        'session_cancelled' => ['Votre séance est annulée', 'La boutique a annulé une séance de votre abonnement « %s » (détails ci-dessous). Rien n\'est perdu : votre séance est de nouveau à planifier depuis votre compte.'],
    ];

    public function __construct(
        private readonly EmailSenderService $mailer,
        private readonly MediaUrlResolver $urls,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param 'payment_failed'|'canceled_unpaid'|'paused'|'canceled'|'cancel_scheduled'|'cancel_at_term_end'|'session_cancelled' $kind
     * @param array{rows?: array<string, string>, payUrl?: ?string} $details lignes ajoutées au récapitulatif, lien de paiement
     */
    public function notify(Subscription $subscription, string $kind, string $locale, string $host, array $details = []): void
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
                'rows' => $details['rows'] ?? [], 'payUrl' => $details['payUrl'] ?? null,
            ], $locale, $this->urls->getEmailLogosBaseUrl($host) . '/');
        } catch (\Throwable $e) {
            $this->logger->error('[Subscription] Courriel non envoyé : ' . $e->getMessage(), ['kind' => $kind, 'subscription' => $subscription->getId()]);
        }
    }
}
