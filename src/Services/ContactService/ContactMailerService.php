<?php

namespace App\Services\ContactService; 

use App\Entity\Contact;
use App\Entity\Entreprise;
use App\Services\EmailConfigurationService\EmailConfigurationService;
use App\Services\EmailConfigurationService\TenantMailerFactory;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\MailerInterface;
use Twig\Environment;

class ContactMailerService
{
    private $mailer;
    private $twig;
    private $emailConfigService;
    private $logger;
    private $emProvider;
    private $tenantMailerFactory;

    public function __construct(
        MailerInterface $mailer,
        Environment $twig,
        EmailConfigurationService $emailConfigService,
        LoggerInterface $logger,
        TenantEntityManagerProvider $emProvider,
        TenantMailerFactory $tenantMailerFactory
    ) {
        $this->mailer = $mailer;
        $this->twig = $twig;
        $this->emailConfigService = $emailConfigService;
        $this->logger = $logger;
        $this->emProvider = $emProvider;
        $this->tenantMailerFactory = $tenantMailerFactory;
    }

    public function sendAdminNotification(Contact $contact, string $locale, string $domain): void
    {
        try {
            $tenantEm = $this->emProvider->getEntityManager();
            $entreprise = $tenantEm->getRepository(Entreprise::class)->findOneBy([]);
            
            if (!$entreprise || !$entreprise->getEmail()) {
                $this->logger->warning("Email de l'entreprise non configuré (Contact Form).");
                return;
            }
            $toEmail = $entreprise->getEmail();

            $emailConfig = $this->emailConfigService->findOneByLocale($locale);
            $emailConfigTranslation = $emailConfig ? $emailConfig->getTranslation($locale) : null;

            if (!$emailConfig || !$emailConfigTranslation) {
                $this->logger->warning('EmailConfiguration introuvable pour la locale ' . $locale . '. E-mail admin de contact non envoyé.');
                return;
            }

            $fromEmail = $emailConfig->getFromEmail();
            $fromName = $emailConfigTranslation->getFromName();
            
            $emailContent = $this->twig->render('emails/contact_admin_notification.html.twig', [
                'contact'   => $contact,
                'fromName'  => $fromName,
                'signature' => $emailConfigTranslation->getSignature(),
                'logoUrl'   => $emailConfig->getLogo(),
                'domain'    => $domain,
            ]);

            $emailMessage = (new Email())
                ->from(sprintf('%s <%s>', $fromName, $fromEmail))
                ->to($toEmail)
                ->replyTo($contact->getEmail())
                ->subject(sprintf('Nouvelle demande de contact de %s', $contact->getName()))
                ->html($emailContent);

            $mailer = $this->tenantMailerFactory->createMailer($emailConfig);
            $mailer->send($emailMessage);

        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de l'envoi de l'e-mail admin de contact : " . $e->getMessage(), [
                'contactId' => $contact->getId(),
                'exception' => $e
            ]);
        }
    }

    public function sendCustomerConfirmation(Contact $contact, string $locale, string $domain): void
    {
        try {
            if (!$contact->getEmail()) {
                throw new \Exception("Le message de contact n'a pas d'e-mail client.");
            }

            $emailConfig = $this->emailConfigService->findOneByLocale($locale);
            $emailConfigTranslation = $emailConfig ? $emailConfig->getTranslation($locale) : null;

            if (!$emailConfig || !$emailConfigTranslation) {
                $this->logger->warning('EmailConfiguration introuvable pour la locale ' . $locale . '. E-mail de confirmation client non envoyé.');
                return;
            }

            $fromEmail = $emailConfig->getFromEmail();
            $fromName = $emailConfigTranslation->getFromName();
            
            $emailContent = $this->twig->render('emails/contact_customer_confirmation.html.twig', [
                'contact'     => $contact,
                'fromName'  => $fromName,
                'signature' => $emailConfigTranslation->getSignature(),
                'logoUrl'   => $emailConfig->getLogo(),
                'domain'    => $domain,
            ]);

            $emailMessage = (new Email())
                ->from(sprintf('%s <%s>', $fromName, $fromEmail))
                ->to($contact->getEmail())
                ->subject('Nous avons bien reçu votre message')
                ->html($emailContent);

            $mailer = $this->tenantMailerFactory->createMailer($emailConfig);
            $mailer->send($emailMessage);

        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de l'envoi de l'e-mail de confirmation client (contact) : " . $e->getMessage(), [
                'contactId' => $contact->getId(),
                'exception' => $e
            ]);
        }
    }
}
