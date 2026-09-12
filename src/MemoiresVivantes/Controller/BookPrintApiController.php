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
        $shippingLevel = $data['shipping_level'] ?? 'EXPEDITED';
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
     * Génère les PDFs finaux et lance la commande d'impression chez Lulu.
     */
    #[Route('/books/{id}/print/order', methods: ['POST'])]
    public function createOrder(string $id, Request $request): JsonResponse
    {
        $book = $this->findBook($id);
        if (!$book) {
            return $this->json(['error' => 'Livre introuvable'], Response::HTTP_NOT_FOUND);
        }

        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            // Pour le dev/test si l'authentification n'est pas passée dans la requête
            $user = $book->getUser();
        }

        $data = json_decode($request->getContent(), true) ?: [];
        $shippingData = $data['shipping_address'] ?? $data;
        $shippingData['quantity'] = $data['quantity'] ?? $shippingData['quantity'] ?? 1;
        $shippingData['shipping_level'] = $data['shipping_level'] ?? $shippingData['shipping_level'] ?? 'EXPEDITED';

        $publicBaseUrl = $request->getSchemeAndHttpHost();

        try {
            $order = $this->createOrderUseCase->execute(
                $book,
                $user,
                $shippingData,
                $publicBaseUrl
            );

            return $this->json($this->serializeOrder($order, $publicBaseUrl), Response::HTTP_CREATED);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
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
