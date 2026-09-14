<?php

namespace App\MemoiresVivantes\Controller;

use App\MemoiresVivantes\Entity\Book;
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
        private readonly LoggerInterface $logger
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

        $data = json_decode($request->getContent(), true) ?: [];

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

        // Détection de l'hôte frontend : Origin/Referer ou repli sur le .env
        $origin = $request->headers->get('Origin') 
            ?: $request->headers->get('Referer') 
            ?: $request->getSchemeAndHttpHost();

        $frontendBaseUrl = rtrim((string) $origin, '/');
        if (preg_match('#^(https?://[^/]+)#', $frontendBaseUrl, $matches)) {
            $frontendBaseUrl = $matches[1];
        }

        $envFrontendUrl = $_ENV['MEMOIRES_FRONTEND_URL'] 
            ?? ('https://memoiresvivantes.' . ($_ENV['FRONTEND_BASE_DOMAIN'] ?? 'arkanoa-media.com'));

        if (empty($frontendBaseUrl) || !filter_var($frontendBaseUrl, FILTER_VALIDATE_URL) || str_contains($frontendBaseUrl, 'backend-strapi.online')) {
            $frontendBaseUrl = rtrim($envFrontendUrl, '/');
        }
        // Force HTTPS pour éviter les redirections non sécurisées
        $frontendBaseUrl = preg_replace('#^http://#', 'https://', $frontendBaseUrl);

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
    public function getPaymentStatus(string $id): JsonResponse
    {
        $book = $this->findBook($id);
        if (!$book) {
            return $this->json(['error' => 'Livre introuvable'], Response::HTTP_NOT_FOUND);
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
