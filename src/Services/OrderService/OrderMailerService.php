<?php

namespace App\Services\OrderService;

use App\Entity\Order;
use App\Entity\Entreprise;
use App\Services\EmailConfigurationService\EmailConfigurationService;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\MailerInterface;
use Twig\Environment;
use DateTimeInterface; // Important pour le typage des dates

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

    public function sendOrderConfirmation(Order $order, string $locale, string $domain): void
    {
        try {
            $user = $order->getUserId();
            if (!$user) throw new \Exception("La commande n'a pas d'utilisateur associé.");

            $emailConfig = $this->emailConfigService->findOneByLocale($locale);
            $emailConfigTranslation = $emailConfig ? $emailConfig->getTranslation($locale) : null;

            if (!$emailConfig || !$emailConfigTranslation) {
                $this->logger->warning('EmailConfiguration introuvable pour la locale ' . $locale);
                return;
            }

            $fromEmail = $emailConfig->getFromEmail();
            $fromName = $emailConfigTranslation->getFromName();
            
            // ✅ AJOUT SÉCURISÉ : On prépare les données
            $itemsData = $this->prepareOrderItemsData($order, $locale);

            $emailContent = $this->twig->render('emails/order_confirmation.html.twig', [
                'order'     => $order,
                'itemsData' => $itemsData, // On passe la variable
                'fromName'  => $fromName,
                'signature' => $emailConfigTranslation->getSignature(),
                'logoUrl'   => $emailConfig->getLogo(),
                'domain'    => $domain,
            ]);

            $emailMessage = (new Email())
                ->from(sprintf('%s <%s>', $fromName, $fromEmail))
                ->to($user->getEmail())
                ->subject('Confirmation de votre commande n°' . $order->getReference())
                ->html($emailContent);

            $this->mailer->send($emailMessage);

        } catch (\Exception $e) {
            $this->logger->error("Erreur email confirmation: " . $e->getMessage());
        }
    }

    public function sendShippingNotification(Order $order, string $locale, string $domain): void
    {
        try {
            $tenantEm = $this->emProvider->getEntityManager();
            $entreprise = $tenantEm->getRepository(Entreprise::class)->findOneBy([]);
            
            if (!$entreprise || !$entreprise->getEmail()) return;
            $toEmail = $entreprise->getEmail();

            $emailConfig = $this->emailConfigService->findOneByLocale($locale);
            $emailConfigTranslation = $emailConfig ? $emailConfig->getTranslation($locale) : null;

            if (!$emailConfig || !$emailConfigTranslation) return;
            
            $fromEmail = $emailConfig->getFromEmail();
            $fromName = $emailConfigTranslation->getFromName();
            $logoUrl = $emailConfig->getLogo();
            $signature = $emailConfigTranslation->getSignature();

            $labels = [];
            if ($order->getShippingOrder() && $order->getShippingOrder()->getParcels()) {
                foreach ($order->getShippingOrder()->getParcels() as $parcel) {
                    if ($parcel->getShippingLabel()) {
                        $labels[] = $parcel->getShippingLabel();
                    }
                }
            }

            // ✅ AJOUT SÉCURISÉ
            $itemsData = $this->prepareOrderItemsData($order, $locale);

            $emailContent = $this->twig->render('emails/shipping_notification.html.twig', [
                'order'     => $order,
                'itemsData' => $itemsData, // On passe la variable
                'labels'    => $labels,
                'fromName'  => $fromName,
                'signature' => $signature,
                'logoUrl'   => $logoUrl,
                'domain'    => $domain,
            ]);

            $emailMessage = (new Email())
                ->from(sprintf('%s <%s>', $fromName, $fromEmail))
                ->to($toEmail)
                ->subject('Nouvelle commande à expédier : ' . $order->getReference())
                ->html($emailContent);

            $this->mailer->send($emailMessage);

        } catch (\Exception $e) {
            $this->logger->error("Erreur email shipping: " . $e->getMessage());
        }
    }

    /**
     * Méthode PRIVÉE pour extraire Booking et Options sans planter
     */
    private function prepareOrderItemsData(Order $order, string $locale): array
    {
        $data = [];
        // Formatage simple pour éviter les erreurs de locale PHP
        $dateFormat = 'Y-m-d H:i'; 

        foreach ($order->getOrderItems() as $item) {
            $variant = $item->getProductVariant();
            
            // 1. Options Dynamiques (Sans traduction pour éviter le crash)
            $options = [];
            if ($variant) {
                // On suppose que getOptionValues existe (c'est dans ton entité)
                foreach ($variant->getOptionValues() as $optionValue) {
                    $parent = $optionValue->getProductOption();
                    if ($parent) {
                        // On prend juste le nom de base en BDD
                        $options[$parent->getName()] = $optionValue->getValue();
                    }
                }
            }

            // 2. Booking
            $bookingData = null;
              if (method_exists($item, 'getBooking')) {
                $booking = $item->getBooking();
                if ($booking) {
                    $bookingData = [
                        'start' => $booking->getStartAt()->format($dateFormat),
                        'end'   => $booking->getEndAt()->format($dateFormat),
                    ];
                }
            }

            // 3. Legacy (Taille/Couleur) - Sans traduction complexe
            $legacyOptions = [];
            if ($variant) {
                if ($variant->getSize()) $legacyOptions['Taille'] = $variant->getSize()->getName();
                if ($variant->getColor()) $legacyOptions['Couleur'] = $variant->getColor()->getName();
            }

            $data[] = [
                'entity'  => $item,        
                'options' => $options,     
                'legacy'  => $legacyOptions, 
                'booking' => $bookingData  
            ];
        }

        return $data;
    }
}