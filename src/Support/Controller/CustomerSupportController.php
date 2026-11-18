<?php

namespace App\Support\Controller;

use App\Entity\User;
use App\Support\Services\SupportException;
use App\Support\UseCase\SupportCustomerUseCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * « Aide et support » des applications du client (lot AIDE-APP, 18/11/2026, `docs/assistance.md`), compte client connecté (règle
 * `^/api/account/support/` : ROLE_USER_INTERNET) :
 * - GET /availability → { available, reason } (le module Assistance du site ; l'application cache l'écran sinon), jamais refusée ;
 * - POST /requests (JSON, ou multipart avec `attachments[]`) { subject, message, category, orderId?, appVersion?, locale? } → 201 { request } ;
 *   canal `app`, application d'origine lue dans le JETON (champ `app`), jamais dans le corps ; 422 { fields }, 409 TOO_MANY_OPEN_REQUESTS,
 *   429 TOO_MANY_REQUESTS_TODAY { scope } ou RATE_LIMITED, 413 ATTACHMENT_TOO_LARGE, 403 MODULE_INACTIVE (garde des modules) ;
 * - GET /requests → { requests [{ reference, subject, category, status: in_progress | answered | closed, unread, createdAt, lastMessageAt }] } ;
 * - GET /requests/{reference} → { request, messages [{ id, from: customer | team, body, createdAt, attachments }] } (jamais une note
 *   interne) ; la lecture remet `unread` à faux ; 404 REQUEST_NOT_FOUND (celle d'un autre client aussi) ;
 * - POST /requests/{reference}/messages { message } (ou multipart) → le fil ; 409 REQUEST_CLOSED ;
 * - GET /requests/{reference}/attachments/{id} → le fichier (hors notes internes).
 */
#[Route('/api/account/support')]
class CustomerSupportController extends AbstractController
{
    public function __construct(private readonly SupportCustomerUseCase $useCase, private readonly JWTTokenManagerInterface $jwt, private readonly TokenStorageInterface $tokens)
    {
    }

    #[Route('/availability', name: 'account_support_availability', methods: ['GET'])]
    public function availability(): JsonResponse
    {
        return $this->json($this->useCase->availability());
    }

    #[Route('/requests', name: 'account_support_requests', methods: ['GET'])]
    public function mine(): Response
    {
        return $this->guard(fn () => $this->json($this->useCase->mine($this->customer())));
    }

    #[Route('/requests', name: 'account_support_request_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        return $this->guard(function () use ($request) {
            [$body, $files] = $this->input($request);

            return $this->json($this->useCase->create($this->customer(), $body, $files, $this->appOfToken()), 201);
        });
    }

    #[Route('/requests/{reference}', name: 'account_support_request', methods: ['GET'], requirements: ['reference' => 'AS-\d{6,}'])]
    public function detail(string $reference): Response
    {
        return $this->guard(fn () => $this->json($this->useCase->detail($this->customer(), $reference)));
    }

    #[Route('/requests/{reference}/messages', name: 'account_support_request_reply', methods: ['POST'], requirements: ['reference' => 'AS-\d{6,}'])]
    public function reply(string $reference, Request $request): Response
    {
        return $this->guard(function () use ($reference, $request) {
            [$body, $files] = $this->input($request);

            return $this->json($this->useCase->reply($this->customer(), $reference, $body, $files), 201);
        });
    }

    #[Route('/requests/{reference}/attachments/{attachmentId}', name: 'account_support_request_attachment', methods: ['GET'], requirements: ['reference' => 'AS-\d{6,}', 'attachmentId' => '\d+'])]
    public function attachment(string $reference, int $attachmentId): Response
    {
        return $this->guard(function () use ($reference, $attachmentId) {
            [$path, $name, $mime] = $this->useCase->attachment($this->customer(), $reference, $attachmentId);
            $response = new BinaryFileResponse($path);
            $response->headers->set('Content-Type', $mime);
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name, 'piece-jointe');
            $response->headers->set('Cache-Control', 'private, no-store');

            return $response;
        });
    }

    private function customer(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /** La famille d'application portée par le jeton (champ `app`, APPS-MEMBRES), ou null */
    private function appOfToken(): ?string
    {
        try {
            $token = $this->tokens->getToken();
            $payload = $token !== null ? $this->jwt->decode($token) : false;

            return is_array($payload) && is_string($payload['app'] ?? null) ? $payload['app'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{0: array<string, mixed>, 1: list<UploadedFile>} le corps (JSON ou formulaire) et les pièces jointes */
    private function input(Request $request): array
    {
        if (str_contains((string) $request->headers->get('Content-Type'), 'json')) {
            $body = json_decode((string) $request->getContent(), true);
            if (!is_array($body) || (array_is_list($body) && $body !== [])) {
                throw SupportException::validation(['body' => 'INVALID_JSON']);
            }

            return [$body, []];
        }

        return [$request->request->all(), array_values(array_filter((array) ($request->files->all()['attachments'] ?? []), fn ($f) => $f instanceof UploadedFile))];
    }

    private function guard(callable $fn): Response
    {
        try {
            return $fn();
        } catch (SupportException $e) {
            $headers = isset($e->details['retryAfterSeconds']) ? ['Retry-After' => (string) $e->details['retryAfterSeconds']] : [];

            return $this->json(['error' => $e->errorCode, 'code' => $e->errorCode, 'message' => $e->getMessage()] + $e->details, $e->status, $headers);
        }
    }
}
