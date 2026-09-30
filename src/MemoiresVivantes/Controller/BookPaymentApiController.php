<?php

namespace App\MemoiresVivantes\Controller;

use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Security\BookAccessGuard;
use App\MemoiresVivantes\Services\FrontendUrlResolver;
use App\MemoiresVivantes\UseCase\Payment\GenerateBookPaymentLinkUseCase;
use App\MemoiresVivantes\UseCase\Payment\GetBookPaymentStatusUseCase;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/memoires/books')]
class BookPaymentApiController extends AbstractController
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly GenerateBookPaymentLinkUseCase $generateBookPaymentLinkUseCase,
        private readonly GetBookPaymentStatusUseCase $getBookPaymentStatusUseCase,
        private readonly LoggerInterface $logger,
        private readonly BookAccessGuard $accessGuard,
        private readonly FrontendUrlResolver $frontendUrl
    ) {}

    /**
     * Crée un lien de paiement Stripe Checkout pour le livre et envoie optionnellement l'email au client.
     */
    #[Route('/{id}/payment-link', methods: ['POST'])]
    #[Route('/{id}/send-payment-link', methods: ['POST'])]
    public function generateAndSendPaymentLink(string $id, Request $request): JsonResponse
    {
        $book = $this->findBook($id);
        if (!$book) {
            return $this->json(['error' => 'Livre introuvable'], Response::HTTP_NOT_FOUND);
        }
        if ($denied = $this->accessGuard->canManage($book)) {
            return $this->json(['error' => $denied[1]], $denied[0]);
        }

        $data = json_decode($request->getContent(), true) ?: [];
        // Montant imposé : administrateur seulement (sinon, celui du livre)
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_SUPER_ADMIN')) {
            unset($data['amount'], $data['currency']);
        }

        $recipientEmail = trim((string) (
            $data['recipient_email'] 
            ?? $data['email'] 
            ?? ($book->getUser() ? $book->getUser()->getEmail() : '')
        ));

        if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return $this->json([
                'error' => 'Une adresse email destinataire valide est requise pour envoyer le lien de paiement.'
            ], Response::HTTP_BAD_REQUEST);
        }

        if (isset($data['amount'])) {
            $cleaned = str_replace([' ', ','], ['', '.'], (string) $data['amount']);
            $amount = is_numeric($cleaned) && (float) $cleaned > 0 ? (float) $cleaned : ($book->getPaymentAmount() ?: 49.00);
        } else {
            $amount = $book->getPaymentAmount() ?: 49.00;
        }

        $currency = strtolower(trim((string) ($data['currency'] ?? ($book->getPaymentCurrency() ?: 'cad'))));
        $sendEmail = isset($data['send_email']) ? filter_var($data['send_email'], FILTER_VALIDATE_BOOLEAN) : true;
        $customMessage = $data['custom_message'] ?? null;

        // Adresse du site frontend du tenant (origine de la requête, sinon domaine du site)
        $frontendBaseUrl = preg_replace('#^http://#', 'https://', $this->frontendUrl->baseUrl($request));

        $successUrl = $data['success_url'] ?? ($frontendBaseUrl . '/payment-success?book_id=' . $book->getId());
        $cancelUrl = $data['cancel_url'] ?? ($frontendBaseUrl . '/books/' . $book->getId());

        try {
            // 1. Exécution du UseCase de génération de session et d'envoi d'email
            $result = $this->generateBookPaymentLinkUseCase->execute(
                $book,
                $recipientEmail,
                $amount,
                $currency,
                $successUrl,
                $cancelUrl,
                $sendEmail,
                $customMessage
            );

            return $this->json([
                'success' => true,
                'message' => $result['email_sent'] 
                    ? 'Lien de paiement généré et envoyé avec succès par email.' 
                    : 'Lien de paiement généré avec succès.',
                'book_id' => (string) $book->getId(),
                'payment_link_url' => $result['url'],
                'payment_status' => $result['payment_status'],
                'amount' => $result['amount'],
                'currency' => $result['currency'],
                'recipient_email' => $recipientEmail,
                'email_sent' => $result['email_sent'],
            ]);

        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                '[BookPaymentApiController] Erreur pour le livre %s : %s in %s:%d' . "\n" . '%s',
                $id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            ));

            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Récupère le statut de paiement actuel d'un livre (avec synchro Stripe via UseCase).
     */
    #[Route('/{id}/payment-status', methods: ['GET'])]
    public function getPaymentStatus(string $id, Request $request): JsonResponse
    {
        $book = $this->findBook($id);
        if (!$book) {
            return $this->json(['error' => 'Livre introuvable'], Response::HTTP_NOT_FOUND);
        }
        if ($denied = $this->accessGuard->canView($book, $request)) {
            return $this->json(['error' => $denied[1]], $denied[0]);
        }

        $paymentStatus = $this->getBookPaymentStatusUseCase->execute($book);

        return $this->json($paymentStatus);
    }

    private function findBook(string $id): ?Book
    {
        try {
            $uuid = Uuid::fromString($id);
            $em = $this->emProvider->getEntityManager();
            return $em->getRepository(Book::class)->find($uuid);
        } catch (\InvalidArgumentException $e) {
            return null;
        }
    }
}
