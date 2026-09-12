<?php

namespace App\MemoiresVivantes\UseCase\Print;

use App\Entity\User;
use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\BookPrintOrder;
use App\MemoiresVivantes\Services\BookPdfGeneratorService;
use App\MemoiresVivantes\Services\LuluPrintService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class CreateBookPrintOrderUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly BookPdfGeneratorService $pdfGeneratorService,
        private readonly LuluPrintService $luluPrintService
    ) {}

    /**
     * Crée une commande d'impression, compile les PDFs et envoie le job à Lulu.
     */
    public function execute(
        Book $book,
        User $user,
        array $shippingData,
        string $publicBaseUrl
    ): BookPrintOrder {
        // Normalisation complète et conforme de l'adresse de livraison
        $normalizedAddress = LuluPrintService::normalizeAddress($shippingData);
        $countryCode = $normalizedAddress['country_code'];

        $recipientName = $normalizedAddress['name'];
        if (($recipientName === 'Destinataire' || empty($recipientName)) && method_exists($user, 'getUserIdentifier')) {
            $recipientName = $user->getUserIdentifier();
            $normalizedAddress['name'] = $recipientName;
        }

        if (empty($normalizedAddress['street1']) || empty($normalizedAddress['city']) || empty($normalizedAddress['postal_code']) || empty($normalizedAddress['country_code'])) {
            throw new BadRequestHttpException('Coordonnées de livraison incomplètes (nom, rue, ville, code postal, pays requis).');
        }

        $em = $this->emProvider->getEntityManager();

        // 1. Détermination ou Génération des PDFs finaux conformes Lulu
        $coverStyle = (string)($shippingData['cover_style'] ?? 'biographic_split');
        $bgColor = !empty($shippingData['bg_color']) ? trim((string)$shippingData['bg_color']) : null;
        if ($bgColor && !str_starts_with($bgColor, '#') && ctype_xdigit($bgColor)) {
            $bgColor = '#' . $bgColor;
        }
        $customCoverPdfUrl = !empty($shippingData['custom_cover_pdf_url']) ? trim((string)$shippingData['custom_cover_pdf_url']) : null;
        $customInteriorPdfUrl = !empty($shippingData['custom_interior_pdf_url']) ? trim((string)$shippingData['custom_interior_pdf_url']) : null;

        if ($customCoverPdfUrl && $customInteriorPdfUrl) {
            $interiorPublicUrl = $customInteriorPdfUrl;
            $coverPublicUrl = $customCoverPdfUrl;
            $pageCount = 64;
        } else {
            $pdfResult = $this->pdfGeneratorService->generateAndSaveBookPdfs($book, $coverStyle, $recipientName, $bgColor);
            $interiorPublicUrl = $customInteriorPdfUrl ?: (rtrim($publicBaseUrl, '/') . '/' . ltrim($pdfResult['interior_path'], '/'));
            $coverPublicUrl = $customCoverPdfUrl ?: (rtrim($publicBaseUrl, '/') . '/' . ltrim($pdfResult['cover_path'], '/'));
            $pageCount = $pdfResult['page_count'];
        }

        // 2. Détermination et validation du mode de transport
        $quantity = max(1, (int)($shippingData['quantity'] ?? 1));
        $rawLevel = !empty($shippingData['shipping_level']) ? (string)$shippingData['shipping_level'] : ($countryCode === 'CA' ? 'PRIORITY_MAIL' : 'MAIL');
        $effectiveShippingLevel = LuluPrintService::sanitizeShippingLevel($rawLevel, $countryCode);

        $costData = $this->luluPrintService->calculatePrintCost(
            $book,
            $normalizedAddress,
            $effectiveShippingLevel,
            $quantity,
            $pageCount
        );

        // 3. Création et persistance de l'entité BookPrintOrder avec données normalisées
        $order = new BookPrintOrder();
        $order->setBook($book);
        $order->setUser($user);
        $order->setCoverStyle($coverStyle);
        $order->setBgColor($bgColor);
        $order->setCustomCoverPdfUrl($customCoverPdfUrl);
        $order->setCustomInteriorPdfUrl($customInteriorPdfUrl);
        $order->setCoverPdfUrl($coverPublicUrl);
        $order->setInteriorPdfUrl($interiorPublicUrl);
        $order->setRecipientName($recipientName);
        $order->setStreet1($normalizedAddress['street1']);
        $order->setStreet2(!empty($normalizedAddress['street2']) ? $normalizedAddress['street2'] : null);
        $order->setCity($normalizedAddress['city']);
        $order->setState(!empty($normalizedAddress['state_code']) ? $normalizedAddress['state_code'] : null);
        $order->setPostalCode($normalizedAddress['postal_code']);
        $order->setCountryCode($normalizedAddress['country_code']);
        $order->setPhoneNumber(!empty($normalizedAddress['phone_number']) ? $normalizedAddress['phone_number'] : null);
        $order->setEmail(!empty($shippingData['email']) ? (string)$shippingData['email'] : (!empty($shippingData['contact_email']) ? (string)$shippingData['contact_email'] : $user->getEmail()));
        $order->setShippingLevel($effectiveShippingLevel);
        $order->setQuantity($quantity);
        $order->setPrintCost($costData['print_cost']);
        $order->setShippingCost($costData['shipping_cost']);
        $order->setTaxCost($costData['tax_cost']);
        $order->setTotalCost($costData['total_cost']);
        $order->setCurrency($costData['currency']);
        $order->setStatus('draft');

        $em->persist($order);
        $em->flush();

        // 4. Transmission à l'API Lulu
        $this->luluPrintService->createPrintJob($order, $interiorPublicUrl, $coverPublicUrl);

        return $order;
    }
}
