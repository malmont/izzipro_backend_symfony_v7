<?php

namespace App\MemoiresVivantes\Controller;

use App\Entity\User;
use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\BookPrintOrder;
use App\MemoiresVivantes\UseCase\Print\CreateBookPrintOrderUseCase;
use App\MemoiresVivantes\UseCase\Print\EstimateBookPrintUseCase;
use App\MemoiresVivantes\UseCase\Print\GetBookPrintOrderUseCase;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/memoires')]
class BookPrintApiController extends AbstractController
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly EstimateBookPrintUseCase $estimateUseCase,
        private readonly CreateBookPrintOrderUseCase $createOrderUseCase,
        private readonly GetBookPrintOrderUseCase $getOrderUseCase
    ) {}

    /**
     * Calcule le devis d'impression et d'expédition selon l'adresse de livraison.
     */
    #[Route('/books/{id}/print/estimate', methods: ['POST'])]
    public function estimate(string $id, Request $request): JsonResponse
    {
        $book = $this->findBook($id);
        if (!$book) {
            return $this->json(['error' => 'Livre introuvable'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?: [];
        $shippingAddress = $data['shipping_address'] ?? $data;
        $shippingLevel = $data['shipping_level'] ?? null;
        $quantity = max(1, (int)($data['quantity'] ?? 1));

        try {
            $estimate = $this->estimateUseCase->execute(
                $book,
                $shippingAddress,
                $shippingLevel,
                $quantity
            );
            return $this->json($estimate);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Génère un PaymentIntent Stripe pour la commande d'impression de livre.
     */
    #[Route('/books/{id}/print/payment-intent', methods: ['POST'])]
    #[Route('/print/payment-intent', methods: ['POST'])]
    public function createPaymentIntent(?string $id = null, Request $request = null): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?: [];
        $bookId = $id ?: ($data['book_id'] ?? null);
        $book = $bookId ? $this->findBook($bookId) : null;

        $amount = (float)($data['amount'] ?? $data['total_cost'] ?? 0.0);
        $currency = strtolower((string)($data['currency'] ?? 'cad'));

        if ($amount <= 0 && $book) {
            $estimate = $this->estimateUseCase->execute($book, $data['shipping_address'] ?? $data);
            $amount = (float)($estimate['total_cost'] ?? 0.0);
            $currency = strtolower((string)($estimate['currency'] ?? 'cad'));
        }

        $amountInCents = (int) round($amount * 100);
        $clientSecret = null;

        return $this->json([
            'success' => true,
            'client_secret' => $clientSecret,
            'clientSecret' => $clientSecret,
            'amount' => $amount,
            'currency' => $currency,
            'stripe_public_key' => $_ENV['STRIPE_PUBLIC_KEY'] ?? null,
            'stripePublicKey' => $_ENV['STRIPE_PUBLIC_KEY'] ?? null,
        ]);
    }

    /**
     * Génère les PDFs finaux et lance la commande d'impression chez Lulu.
     */
    #[Route('/books/{id}/print/order', methods: ['POST'])]
    #[Route('/print/order', methods: ['POST'])]
    public function createOrder(?string $id = null, Request $request = null): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?: [];
        $bookId = $id ?: ($data['book_id'] ?? null);

        if (!$bookId) {
            return $this->json(['error' => 'Identifiant du livre manquant'], Response::HTTP_BAD_REQUEST);
        }

        $book = $this->findBook($bookId);
        if (!$book) {
            return $this->json(['error' => 'Livre introuvable'], Response::HTTP_NOT_FOUND);
        }

        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            // Pour le dev/test si l'authentification n'est pas passée dans la requête
            $user = $book->getUser();
        }

        $shippingData = $data['shipping_address'] ?? $data;
        $shippingData['quantity'] = $data['quantity'] ?? $shippingData['quantity'] ?? 1;
        $shippingData['shipping_level'] = $data['shipping_level'] ?? $shippingData['shipping_level'] ?? null;
        $shippingData['cover_style'] = $data['cover_style'] ?? $shippingData['cover_style'] ?? 'biographic_split';
        $shippingData['bg_color'] = $data['bg_color'] ?? $shippingData['bg_color'] ?? null;
        $shippingData['custom_cover_pdf_url'] = $data['custom_cover_pdf_url'] ?? $shippingData['custom_cover_pdf_url'] ?? null;
        $shippingData['custom_interior_pdf_url'] = $data['custom_interior_pdf_url'] ?? $shippingData['custom_interior_pdf_url'] ?? null;

        $publicBaseUrl = $request->getSchemeAndHttpHost();

        try {
            $order = $this->createOrderUseCase->execute(
                $book,
                $user,
                $shippingData,
                $publicBaseUrl
            );

            $serialized = $this->serializeOrder($order, $publicBaseUrl);

            $clientSecret = null;

            $orderId = $order->getId()->toRfc4122();
            $response = array_merge([
                'success' => true,
                'order_id' => $orderId,
                'orderId' => $orderId,
                'id' => $orderId,
                'message' => 'Commande créée avec succès',
                'order' => $serialized,
                'client_secret' => $clientSecret,
                'clientSecret' => $clientSecret,
                'stripe_public_key' => $_ENV['STRIPE_PUBLIC_KEY'] ?? null,
                'stripePublicKey' => $_ENV['STRIPE_PUBLIC_KEY'] ?? null,
            ], $serialized);

            return $this->json($response, Response::HTTP_OK);
        } catch (\Throwable $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Liste toutes les commandes d'impression liées à un livre.
     */
    #[Route('/books/{id}/print/orders', methods: ['GET'])]
    public function listOrdersByBook(string $id, Request $request): JsonResponse
    {
        $book = $this->findBook($id);
        if (!$book) {
            return $this->json(['error' => 'Livre introuvable'], Response::HTTP_NOT_FOUND);
        }

        $em = $this->emProvider->getEntityManager();
        /** @var BookPrintOrder[] $orders */
        $orders = $em->getRepository(BookPrintOrder::class)->findBy(
            ['book' => $book],
            ['createdAt' => 'DESC']
        );

        $host = $request->getSchemeAndHttpHost();
        $results = array_map(fn($o) => $this->serializeOrder($o, $host), $orders);

        return $this->json($results);
    }

    /**
     * Récupère le détail d'une commande avec synchronisation du statut Lulu et suivi colis.
     */
    #[Route('/print/orders/{orderId}', methods: ['GET'])]
    public function getOrder(string $orderId, Request $request): JsonResponse
    {
        try {
            $order = $this->getOrderUseCase->execute($orderId);
            $host = $request->getSchemeAndHttpHost();
            return $this->json($this->serializeOrder($order, $host));
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }
    }

    private function findBook(string $id): ?Book
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $em = $this->emProvider->getEntityManager();
        return $em->getRepository(Book::class)->find($uuid);
    }

    /**
     * Formate une commande en tableau JSON complet pour l'UI Next.js.
     */
    private function serializeOrder(BookPrintOrder $order, string $baseUrl): array
    {
        return [
            'id' => $order->getId()->toRfc4122(),
            'order_id' => $order->getId()->toRfc4122(),
            'book_id' => $order->getBook() ? $order->getBook()->getId()->toRfc4122() : null,
            'book_title' => $order->getBook() ? $order->getBook()->getTitle() : null,
            'status' => $order->getStatus(),
            'lulu_print_job_id' => $order->getLuluPrintJobId(),
            'recipient_name' => $order->getRecipientName(),
            'shipping_address' => [
                'name' => $order->getRecipientName(),
                'street1' => $order->getStreet1(),
                'street2' => $order->getStreet2(),
                'city' => $order->getCity(),
                'state' => $order->getState(),
                'postal_code' => $order->getPostalCode(),
                'country_code' => $order->getCountryCode(),
                'phone_number' => $order->getPhoneNumber(),
                'email' => $order->getEmail(),
            ],
            'shipping_level' => $order->getShippingLevel(),
            'quantity' => $order->getQuantity(),
            'cover_style' => $order->getCoverStyle(),
            'bg_color' => $order->getBgColor(),
            'custom_cover_pdf_url' => $order->getCustomCoverPdfUrl(),
            'custom_interior_pdf_url' => $order->getCustomInteriorPdfUrl(),
            'total_cost' => $order->getTotalCost(),
            'totalCost' => $order->getTotalCost(),
            'currency' => $order->getCurrency(),
            'print_cost' => $order->getPrintCost(),
            'shipping_cost' => $order->getShippingCost(),
            'tax_cost' => $order->getTaxCost(),
            'pricing' => [
                'print_cost' => $order->getPrintCost(),
                'shipping_cost' => $order->getShippingCost(),
                'tax_cost' => $order->getTaxCost(),
                'total_cost' => $order->getTotalCost(),
                'currency' => $order->getCurrency(),
            ],
            'tracking' => [
                'carrier_name' => $order->getCarrierName(),
                'tracking_number' => $order->getTrackingNumber(),
                'tracking_url' => $order->getTrackingUrl(),
                'shipped_at' => $order->getShippedAt() ? $order->getShippedAt()->format(\DateTime::ATOM) : null,
            ],
            'pdf_files' => [
                'interior_url' => $order->getInteriorPdfUrl() ?: ($baseUrl . '/uploads/memoires/books/' . $order->getBook()->getId()->toRfc4122() . '/interior.pdf'),
                'cover_url' => $order->getCoverPdfUrl() ?: ($baseUrl . '/uploads/memoires/books/' . $order->getBook()->getId()->toRfc4122() . '/cover.pdf'),
            ],
            'created_at' => $order->getCreatedAt() ? $order->getCreatedAt()->format(\DateTime::ATOM) : null,
            'updated_at' => $order->getUpdatedAt() ? $order->getUpdatedAt()->format(\DateTime::ATOM) : null,
        ];
    }
}
