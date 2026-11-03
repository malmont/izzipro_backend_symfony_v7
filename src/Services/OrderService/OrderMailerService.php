<?php

namespace App\Services\OrderService;

use App\Entity\Order;
use App\Entity\Entreprise;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use DateTimeInterface;

/**
 * Courriels d'une commande web. Confirmation au client dans sa langue (fr ou en d'après la locale de la session de paiement,
 * sinon la langue du site ; 03/11/2026) ; avis d'expédition au marchand, en français. Montants dans la devise du site
 * (TenantCurrencyProvider : fiche Entreprise, CAD à défaut ; la commande n'enregistre pas de devise), formatés selon la langue
 * et le pays du site (`intlLocale`).
 */
class OrderMailerService
{
    private $emailSenderService;
    private $logger;
    private $emProvider;

    public function __construct(
        EmailSenderService $emailSenderService,
        LoggerInterface $logger,
        TenantEntityManagerProvider $emProvider,
        private readonly ?TenantCurrencyProvider $currency = null,
        private readonly ?EmailLocale $emailLocale = null
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

            $language = $this->emailLocale?->language($locale) ?? EmailLocale::FRENCH;
            $itemsData = $this->prepareOrderItemsData($order, $language);

            $this->emailSenderService->sendCustomerEmail(
                $user->getEmail(),
                'order.subject',
                ['%reference%' => (string) $order->getReference()],
                'emails/order_confirmation.html.twig',
                [
                    'order'     => $order,
                    'itemsData' => $itemsData,
                    'currency'  => $this->currencyCode(),
                ],
                $language,
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

            // Courriel du marchand : toujours en français (03/11/2026) ; devise du site, montants au format de la langue et du pays du site
            $itemsData = $this->prepareOrderItemsData($order, EmailLocale::FRENCH);

            $this->emailSenderService->sendTemplatedEmail(
                $toEmail,
                'Nouvelle commande à expédier : ' . $order->getReference(),
                'emails/shipping_notification.html.twig',
                [
                    'order'     => $order,
                    'itemsData' => $itemsData,
                    'labels'    => $labels,
                    'currency'  => $this->currencyCode(),
                    'intlLocale' => $this->emailLocale?->intl(EmailLocale::FRENCH) ?? EmailLocale::FRENCH,
                ],
                $locale,
                $domain
            );
        } catch (\Exception $e) {
            $this->logger->error("Erreur email shipping: " . $e->getMessage());
        }
    }

    /** Devise du site (fiche Entreprise), CAD à défaut ; avant le 03/11/2026 les gabarits écrivaient CAD en dur */
    private function currencyCode(): string
    {
        try {
            return $this->currency?->code() ?? TenantCurrencyProvider::DEFAULT;
        } catch (\Throwable) {
            return TenantCurrencyProvider::DEFAULT;
        }
    }

    /**
     * Méthode PRIVÉE pour extraire Booking et Options sans planter. $language : fr (dates `Y-m-d H:i`, libellés Taille / Couleur,
     * inchangés) ou en (dates au format de la langue et du pays du site, libellés traduits).
     */
    private function prepareOrderItemsData(Order $order, string $language): array
    {
        $data = [];
        $dateFormat = 'Y-m-d H:i';
        $intl = $language !== EmailLocale::FRENCH ? new \IntlDateFormatter($this->emailLocale?->intl($language) ?? $language, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT) : null;
        $formatDate = fn (\DateTimeInterface $date): string => $intl !== null ? (string) $intl->format($date) : $date->format($dateFormat);

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
                    $tz = new \DateTimeZone('America/Toronto');
                    $startAt = $booking->getStartAt() ? (clone $booking->getStartAt())->setTimezone($tz) : null;
                    $endAt = $booking->getEndAt() ? (clone $booking->getEndAt())->setTimezone($tz) : null;
                    $bookingData = [
                        'start' => $startAt ? $formatDate($startAt) : '',
                        'end'   => $endAt ? $formatDate($endAt) : '',
                    ];
                }
            }

            // 3. Legacy (Taille/Couleur) : libellés dans la langue du courriel
            $legacyOptions = [];
            if ($variant) {
                if ($variant->getSize()) $legacyOptions[$this->emailSenderService->subject('order.size', [], $language)] = $variant->getSize()->getName();
                if ($variant->getColor()) $legacyOptions[$this->emailSenderService->subject('order.color', [], $language)] = $variant->getColor()->getName();
            }

            $data[] = [
                'entity'   => $item,
                'options'  => $options,
                'legacy'   => $legacyOptions,
                'booking'  => $bookingData,
                'saleUnit' => $item->getSaleUnit() ?? (($variant && $variant->getProduct()->getSaleUnit()) ? $variant->getProduct()->getSaleUnit()->getName() : null)
            ];
        }

        return $data;
    }
}
