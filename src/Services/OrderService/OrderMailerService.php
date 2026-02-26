<?php

namespace App\Services\OrderService;

use App\Entity\Order;
use App\Entity\Entreprise;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use DateTimeInterface;

class OrderMailerService
{
    private $emailSenderService;
    private $logger;
    private $emProvider;

    public function __construct(
        EmailSenderService $emailSenderService,
        LoggerInterface $logger,
        TenantEntityManagerProvider $emProvider
    ) {
        $this->emailSenderService = $emailSenderService;
        $this->logger = $logger;
        $this->emProvider = $emProvider;
    }

    public function sendOrderConfirmation(Order $order, string $locale, string $domain): void
    {
        try {
            $user = $order->getUserId();
            if (!$user) throw new \Exception("La commande n'a pas d'utilisateur associé.");

            $itemsData = $this->prepareOrderItemsData($order, $locale);

            $this->emailSenderService->sendTemplatedEmail(
                $user->getEmail(),
                'Confirmation de votre commande n°' . $order->getReference(),
                'emails/order_confirmation.html.twig',
                [
                    'order'     => $order,
                    'itemsData' => $itemsData,
                ],
                $locale,
                $domain
            );
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

            $this->emailSenderService->sendTemplatedEmail(
                $toEmail,
                'Nouvelle commande à expédier : ' . $order->getReference(),
                'emails/shipping_notification.html.twig',
                [
                    'order'     => $order,
                    'itemsData' => $itemsData,
                    'labels'    => $labels,
                ],
                $locale,
                $domain
            );
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
        $dateFormat = 'Y-m-d H:i';

        foreach ($order->getOrderItems() as $item) {
            $variant = $item->getProductVariant();

            $options = [];
            if ($variant) {
                foreach ($variant->getOptionValues() as $optionValue) {
                    $parent = $optionValue->getProductOption();
                    if ($parent) {
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
