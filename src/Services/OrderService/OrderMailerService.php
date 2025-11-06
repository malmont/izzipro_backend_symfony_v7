<?php

namespace App\Services\OrderService;

use App\Entity\Order;
use App\Entity\Entreprise;
use App\Entity\ShippingLabel;
use App\Services\EmailConfigurationService\EmailConfigurationService;
use App\Services\TenantEntityManagerProvider;
use App\Repository\EntrepriseRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\MailerInterface;
use Twig\Environment;
use Doctrine\ORM\EntityManagerInterface;

class OrderMailerService
{
    private $mailer;
    private $twig;
    private $emailConfigService;
    private $logger;
    private $emProvider;

    public function __construct(
        MailerInterface $mailer,
        Environment $twig,
        EmailConfigurationService $emailConfigService,
        LoggerInterface $logger,
        TenantEntityManagerProvider $emProvider
    ) {
        $this->mailer = $mailer;
        $this->twig = $twig;
        $this->emailConfigService = $emailConfigService;
        $this->logger = $logger;
        $this->emProvider = $emProvider;
    }

    /**
     * Envoie un email de confirmation de commande au client.
     */
    public function sendOrderConfirmation(Order $order, string $locale, string $domain): void
    {
        try {
            $user = $order->getUserId();
            if (!$user) {
                throw new \Exception("La commande n'a pas d'utilisateur associé.");
            }

            $emailConfig = $this->emailConfigService->findOneByLocale($locale);
            $emailConfigTranslation = $emailConfig ? $emailConfig->getTranslation($locale) : null;

            if (!$emailConfig || !$emailConfigTranslation) {
                $this->logger->warning('EmailConfiguration introuvable pour la locale ' . $locale . '. Email de confirmation de commande non envoyé.');
                return;
            }

            $fromEmail = $emailConfig->getFromEmail();
            $fromName = $emailConfigTranslation->getFromName();
            
            // Le domaine est maintenant passé en argument.

            $emailContent = $this->twig->render('emails/order_confirmation.html.twig', [
                'order'     => $order,
                'fromName'  => $fromName,
                'signature' => $emailConfigTranslation->getSignature(),
                'logoUrl'   => $emailConfig->getLogo(),
                'domain'    => $domain, // <-- Il est passé ici
            ]);

            $emailMessage = (new Email())
                ->from(sprintf('%s <%s>', $fromName, $fromEmail))
                ->to($user->getEmail())
                ->subject('Confirmation de votre commande n°' . $order->getReference())
                ->html($emailContent);

            $this->mailer->send($emailMessage);

        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de l'envoi de l'email de confirmation de commande : " . $e->getMessage(), [
                'orderId' => $order->getId(),
                'exception' => $e
            ]);
        }
    }

    /**
     * Envoie un email à l'entreprise avec les étiquettes d'expédition.
     */
    public function sendShippingNotification(Order $order, string $locale, string $domain): void
    {
        try {
            $tenantEm = $this->emProvider->getEntityManager();
            $entreprise = $tenantEm->getRepository(Entreprise::class)->findOneBy([]);
            
            if (!$entreprise || !$entreprise->getEmail()) {
                $this->logger->warning("Email de l'entreprise non configuré. Email d'étiquette non envoyé.");
                return;
            }
            $toEmail = $entreprise->getEmail();
            $emailConfig = $this->emailConfigService->findOneByLocale($locale);
            $emailConfigTranslation = $emailConfig ? $emailConfig->getTranslation($locale) : null;

            if (!$emailConfig || !$emailConfigTranslation) {
                $this->logger->warning("EmailConfiguration introuvable. Email d'étiquette non envoyé.");
                return;
            }
            
            // Logique pour récupérer le domaine et le logo
            $fromEmail = $emailConfig->getFromEmail();
            $fromName = $emailConfigTranslation->getFromName();
            // La variable $domain vient des arguments de la fonction
            $logoUrl = $emailConfig->getLogo();
            $signature = $emailConfigTranslation->getSignature();

            // Logique pour récupérer les étiquettes
            $labels = [];
            if ($order->getShippingOrder() && $order->getShippingOrder()->getParcels()) {
                foreach ($order->getShippingOrder()->getParcels() as $parcel) {
                    if ($parcel->getShippingLabel()) {
                        $labels[] = $parcel->getShippingLabel();
                    }
                }
            }

            // Log corrigé : on informe qu'on envoie SANS étiquette
            if (empty($labels)) {
                $this->logger->info("Aucune étiquette d'expédition trouvée pour la commande " . $order->getId() . ". Envoi de la notification sans étiquettes.");
            }
            
            // Variables manquantes (signature, logoUrl, domain) ajoutées
            $emailContent = $this->twig->render('emails/shipping_notification.html.twig', [
                'order'     => $order,
                'labels'    => $labels,
                'fromName'  => $fromName,
                'signature' => $signature,
                'logoUrl'   => $logoUrl,
                'domain'    => $domain, // <-- Il est passé ici
            ]);

            $emailMessage = (new Email())
                ->from(sprintf('%s <%s>', $fromName, $fromEmail))
                ->to($toEmail)
                ->subject('Nouvelle commande à expédier : ' . $order->getReference())
                ->html($emailContent);

            $this->mailer->send($emailMessage);

        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de l'envoi de l'email d'expédition : " . $e->getMessage(), [
                'orderId' => $order->getId(),
                'exception' => $e
            ]);
        }
    }
}