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
        private readonly ?EmailLocale $emailLocale = null,
        private readonly ?\App\Services\Booking\BookingClock $clock = null // fuseau de la boutique (04/11/2026)
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

    /**
     * Courriel d'opération au client (lot 3, 07/11/2026) : `order_refunded` (amountCents, reason), `order_cancelled` (amountCents | null,
     * reason), `order_receipt` (totalCents, paidCents, refundedCents, receiptUrls). Langue du site, montants dans sa devise.
     */
    public function sendOperationNotice(Order $order, string $kind, array $data, string $domain): bool
    {
        $email = (string) ($order->getUserId()?->getEmail() ?? '');
        if ($email === '' || str_ends_with($email, '@compte-supprime.invalid') || !in_array($kind, ['order_refunded', 'order_cancelled', 'order_receipt'], true)) {
            return false;
        }
        $language = $this->emailLocale?->language(null) ?? EmailLocale::FRENCH;
        $currency = $this->currencyCode();
        $money = fn (?int $cents): ?string => $cents === null ? null : ($language === 'en' ? number_format($cents / 100, 2, '.', ',') . ' ' . $currency : number_format($cents / 100, 2, ',', ' ') . ' ' . $currency);
        $context = [
            'kind' => $kind, 'reference' => (string) $order->getReference(), 'firstname' => $order->getUserId()?->getFirstname(),
            'amountText' => $money($data['amountCents'] ?? null), 'reason' => isset($data['reason']) && trim((string) $data['reason']) !== '' ? trim((string) $data['reason']) : null,
            'totalText' => $money($data['totalCents'] ?? null), 'paidText' => $money($data['paidCents'] ?? null), 'refundedText' => ($data['refundedCents'] ?? 0) > 0 ? $money($data['refundedCents']) : null,
            'receiptUrls' => array_values(array_filter($data['receiptUrls'] ?? [])),
            'dateText' => $order->getOrderDate() ? ($language === 'en' ? $order->getOrderDate()->format('Y-m-d') : $order->getOrderDate()->format('d/m/Y')) : '',
        ];
        try {
            return $this->emailSenderService->sendCustomerEmail($email, $kind . '.subject', ['%reference%' => (string) $order->getReference()], 'emails/order_notice.html.twig', $context, $language, $domain, $kind . '.subject');
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Erreur courriel %s : %s', $kind, $e->getMessage()));

            return false;
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
                    $tz = $this->clock?->timezone() ?? new \DateTimeZone(\App\Services\Booking\BookingClock::DEFAULT); // fuseau de la boutique (04/11/2026)
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
