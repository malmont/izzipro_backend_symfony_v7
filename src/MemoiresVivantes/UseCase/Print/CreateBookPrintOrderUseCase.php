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
        private readonly LuluPrintService $luluPrintService,
        private readonly string $projectDir,
        private readonly string $storagePublicUrl = ''
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
        if ($recipientName === 'Destinataire' || empty($recipientName)) {
            // Nom du client plutôt que son identifiant de connexion (une adresse e-mail)
            $recipientName = trim($user->getFirstname() . ' ' . $user->getLastname()) ?: $user->getUserIdentifier();
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
        foreach ([$customCoverPdfUrl, $customInteriorPdfUrl] as $customUrl) {
            if ($customUrl !== null) {
                $this->assertBookPdfUrl($book, $customUrl, $publicBaseUrl);
            }
        }

        if ($customCoverPdfUrl && $customInteriorPdfUrl) {
            $interiorPublicUrl = $customInteriorPdfUrl;
            $coverPublicUrl = $customCoverPdfUrl;
            $pageCount = 64;
        } else {
            // Auteur imprimé sur la couverture : celui du livre (ou celui fourni), jamais le destinataire du colis
            $authorName = BookPdfGeneratorService::normalizeAuthorName($shippingData['author_name'] ?? null);
            $pdfResult = $this->pdfGeneratorService->generateAndSaveBookPdfs($book, $coverStyle, $authorName, $bgColor);
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

    /**
     * Un PDF fourni par l'appelant doit être l'un de ceux générés pour ce livre, sur notre stockage : Lulu imprime ce
     * que désigne l'URL, aux frais de la plateforme.
     */
    private function assertBookPdfUrl(Book $book, string $url, string $publicBaseUrl): void
    {
        $bookId = $book->getId()->toRfc4122();
        $parts = parse_url($url);
        $allowedHosts = array_filter([parse_url($publicBaseUrl, PHP_URL_HOST), parse_url($this->storagePublicUrl, PHP_URL_HOST)]);
        $path = (string) ($parts['path'] ?? '');

        $valid = in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && in_array($parts['host'] ?? '', $allowedHosts, true)
            && !isset($parts['query'])
            && preg_match('#/uploads/memoires/books/' . preg_quote($bookId, '#') . '/([A-Za-z0-9._-]+\.pdf)$#', $path, $m)
            && is_file($this->projectDir . '/var/storage/public_bucket/uploads/memoires/books/' . $bookId . '/' . $m[1]);

        if (!$valid) {
            throw new BadRequestHttpException('PDF refusé : seuls les PDF générés pour ce livre peuvent être imprimés.');
        }
    }
}
