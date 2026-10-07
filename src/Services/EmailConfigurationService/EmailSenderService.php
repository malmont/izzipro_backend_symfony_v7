<?php

namespace App\Services\EmailConfigurationService;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\MailerInterface;
use Twig\Environment;

class EmailSenderService
{
    private MailerInterface $mailer;
    private Environment $twig;
    private EmailConfigurationService $emailConfigService;
    private EmailLogoHelper $emailLogoHelper;
    private LoggerInterface $logger;
    private TenantMailerFactory $tenantMailerFactory;

    private const LOGO_CID = 'email_logo';

    public function __construct(
        MailerInterface $mailer,
        Environment $twig,
        EmailConfigurationService $emailConfigService,
        EmailLogoHelper $emailLogoHelper,
        LoggerInterface $logger,
        TenantMailerFactory $tenantMailerFactory,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(default::MAILER_DSN)%')]
        private readonly ?string $platformDsn = null
    ) {
        $this->tenantMailerFactory = $tenantMailerFactory;
        $this->mailer = $mailer;
        $this->twig = $twig;
        $this->emailConfigService = $emailConfigService;
        $this->emailLogoHelper = $emailLogoHelper;
        $this->logger = $logger;
    }

    /**
     * Factorise la récupération de la configuration de l'email, la préparation du contexte Twig, et l'envoi.
     *
     * @param string $to Adresse email du destinataire
     * @param string $subject Sujet de l'email
     * @param string $template Chemin vers le template Twig (ex: 'emails/order_confirmation.html.twig')
     * @param array $context Variables du template métier (ex: ['order' => $order, 'itemsData' => $itemsData])
     * @param string $locale Locale pour charger la bonne configuration
     * @param string $domain Domaine pour générer l'URL absolue du logo
     * @param string|null $customFromName Possibilité de surcharger le nom d'envoi par défaut
     */
    public function sendTemplatedEmail(
        string $to,
        string $subject,
        string $template,
        array $context,
        string $locale,
        string $domain,
        ?string $customFromName = null,
        ?string $replyTo = null
    ): bool {
        try {
            $emailConfig = $this->emailConfigService->findOneByLocale($locale);

            if (!$emailConfig) {
                $this->logger->warning('EmailConfiguration introuvable.');
                return false;
            }

            $emailConfigTranslation = $emailConfig->getTranslation($locale);

            $fromEmail = $emailConfig->getFromEmail() ?: 'noreply@votredomaine.com';

            // 1. Surcharge personnalisée
            $fromName = $customFromName;

            // 2. Traduction si disponible et non vide
            // if (empty($fromName) && $emailConfigTranslation) {
            //     $fromName = $emailConfigTranslation->getFromName();
            // }

            // 3. Configuration Globale
            if (empty($fromName)) {
                $fromName = $emailConfig->getFromName();
            }

            // 4. Fallback de sécurité
            if (empty($fromName)) {
                $fromName = 'Support';
            }

            // Logo incorporé à l'e-mail quand son fichier est sur le serveur (affiché même si la messagerie bloque
            // les images distantes) ; sinon adresse publique absolue
            $logoPath = $this->emailLogoHelper->getLocalPath($emailConfig);
            $logoUrl = $logoPath !== null ? 'cid:' . self::LOGO_CID : $this->emailLogoHelper->getLogoUrl($emailConfig, $domain);
            $signature = $emailConfigTranslation ? $emailConfigTranslation->getSignature() : '';

            // Injecte les variables globales dans le contexte pour Twig
            $context['fromName'] = $fromName;
            $context['signature'] = $signature;
            $context['logoUrl'] = $logoUrl;
            $context['domain'] = $context['domain'] ?? $domain;

            $emailContent = $this->twig->render($template, $context);

            $emailMessage = (new Email())
                ->from(new \Symfony\Component\Mime\Address($fromEmail, $fromName))
                ->to($to)
                ->subject($subject)
                ->html($emailContent);

            if ($replyTo) {
                $emailMessage->replyTo($replyTo);
            }

            if ($logoPath !== null) {
                $emailMessage->embedFromPath($logoPath, self::LOGO_CID);
            }

            // Serveur d'envoi du site (configuration e-mail du tenant), comme les e-mails de rendez-vous et de
            // paiement ; le serveur global (.env) ne sert que si le site n'a pas le sien. Avant, ces e-mails (mot de
            // passe oublié, code de connexion, contact, commande) passaient toujours par le serveur global.
            $this->tenantMailerFactory->createMailer($emailConfig)->send($emailMessage);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error("Erreur lors de l'envoi de l'email: " . $e->getMessage());

            return false;
        }
    }

    /**
     * E-mail de la plateforme (code de connexion d'un administrateur) : par le serveur global (MAILER_DSN, .env),
     * avec pour expéditeur le compte qui s'y authentifie. Sert quand le site n'a pas de configuration d'envoi : sans
     * cela, le code ne partait pas et l'administrateur ne pouvait plus se connecter.
     */
    public function sendPlatformEmail(string $to, string $subject, string $template, array $context): bool
    {
        $from = $this->platformSender();
        if ($from === null) {
            $this->logger->error('E-mail de la plateforme impossible : pas de compte expéditeur dans MAILER_DSN.');

            return false;
        }
        try {
            $this->mailer->send((new Email())
                ->from(new \Symfony\Component\Mime\Address($from, 'Arkanoa'))
                ->to($to)
                ->subject($subject)
                ->html($this->twig->render($template, $context + ['fromName' => 'Arkanoa', 'signature' => '', 'logoUrl' => null, 'domain' => ''])));

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Erreur lors de l\'envoi d\'un e-mail de la plateforme : ' . $e->getMessage());

            return false;
        }
    }

    /** Adresse du compte SMTP du serveur global, ou null si MAILER_DSN n'en a pas */
    private function platformSender(): ?string
    {
        try {
            $user = \Symfony\Component\Mailer\Transport\Dsn::fromString((string) $this->platformDsn)->getUser();
        } catch (\Throwable) {
            return null;
        }

        return is_string($user) && filter_var($user, FILTER_VALIDATE_EMAIL) ? $user : null;
    }
}
