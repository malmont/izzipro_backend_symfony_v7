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
        if (empty($shippingData['recipient_name']) || empty($shippingData['street1']) || empty($shippingData['city']) || empty($shippingData['postal_code']) || empty($shippingData['country_code'])) {
            throw new BadRequestHttpException('Coordonnées de livraison incomplètes (nom, rue, ville, code postal, pays requis).');
        }

        $em = $this->emProvider->getEntityManager();

        // 1. Génération des PDFs finaux conformes Lulu
        $pdfResult = $this->pdfGeneratorService->generateAndSaveBookPdfs($book);
        $interiorPublicUrl = rtrim($publicBaseUrl, '/') . '/' . ltrim($pdfResult['interior_path'], '/');
        $coverPublicUrl = rtrim($publicBaseUrl, '/') . '/' . ltrim($pdfResult['cover_path'], '/');

        // 2. Calcul du tarif exact
        $quantity = max(1, (int)($shippingData['quantity'] ?? 1));
        $shippingLevel = strtoupper($shippingData['shipping_level'] ?? 'MAIL');

        $costData = $this->luluPrintService->calculatePrintCost(
            $book,
            [
                'name' => $shippingData['recipient_name'],
                'street1' => $shippingData['street1'],
                'street2' => $shippingData['street2'] ?? '',
                'city' => $shippingData['city'],
                'state_code' => $shippingData['state'] ?? '',
                'postcode' => $shippingData['postal_code'],
                'country_code' => $shippingData['country_code'],
                'phone_number' => $shippingData['phone_number'] ?? null,
            ],
            $shippingLevel,
            $quantity,
            $pdfResult['page_count']
        );

        // 3. Création et persistance de l'entité BookPrintOrder
        $order = new BookPrintOrder();
        $order->setBook($book);
        $order->setUser($user);
        $order->setRecipientName($shippingData['recipient_name']);
        $order->setStreet1($shippingData['street1']);
        $order->setStreet2($shippingData['street2'] ?? null);
        $order->setCity($shippingData['city']);
        $order->setState($shippingData['state'] ?? null);
        $order->setPostalCode($shippingData['postal_code']);
        $order->setCountryCode($shippingData['country_code']);
        $order->setPhoneNumber($shippingData['phone_number'] ?? null);
        $order->setEmail($shippingData['email'] ?? $user->getEmail());
        $order->setShippingLevel($shippingLevel);
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
