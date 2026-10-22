<?php

namespace App\Controller\PosController;

use App\Services\PosAuditService\PosReceiptMailer;
use App\UseCase\PosReceiptUseCase\PosReceiptUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page publique d'un reçu (F3, 24/10/2026), atteinte par le QR code du ticket : /api/r/<jeton signé>. Servie sous /api/
 * pour être routée vers le backend sur l'hôte public de chaque site sans toucher au site Next.js. Non indexée, sans
 * cache ; ?format=pdf renvoie le PDF. Aucune donnée au-delà du ticket (pas de client, pas d'adresse).
 */
class PosPublicReceiptController extends AbstractController
{
    public function __construct(private readonly PosReceiptUseCase $receipts, private readonly PosReceiptMailer $mailer)
    {
    }

    #[Route('/api/r/{token}', name: 'pos_public_receipt', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_.-]{20,200}'])]
    public function show(string $token, Request $request): Response
    {
        $locale = preg_match('/^[a-z]{2}$/', (string) $request->query->get('locale', 'fr')) ? (string) $request->query->get('locale') : 'fr';
        $result = $this->receipts->public($token, $locale);
        $profile = $this->mailer->profile($locale);
        if ($result['status'] !== 'valid') {
            [$title, $message] = match ($result['status']) {
                'expired' => ['Reçu expiré', 'Ce lien n\'est plus valide : la durée de consultation du reçu est dépassée. Adressez-vous au magasin pour un duplicata.'],
                'revoked' => ['Reçu indisponible', 'Ce lien a été désactivé par le magasin.'],
                default => ['Reçu introuvable', 'Ce lien ne correspond à aucun reçu.'],
            };

            return $this->headers(new Response($this->renderView('pos/receipt_public.html.twig', ['title' => $title, 'message' => $message, 'profile' => $profile]), $result['status'] === 'invalid' ? 404 : 410));
        }
        $block = $result['block'];
        if ($request->query->get('format') === 'pdf') {
            $response = new Response($this->mailer->pdf($block, $profile, false), 200, ['Content-Type' => 'application/pdf',
                'Content-Disposition' => sprintf('inline; filename="recu-%s.pdf"', preg_replace('/[^A-Za-z0-9-]/', '', (string) ($block['receiptReference'] ?? $block['receiptNumber'])))]);

            return $this->headers($response);
        }

        return $this->headers(new Response($this->mailer->html($block, $profile, false, 'Reçu n° ' . ($block['receiptReference'] ?? $block['receiptNumber']))));
    }

    private function headers(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
