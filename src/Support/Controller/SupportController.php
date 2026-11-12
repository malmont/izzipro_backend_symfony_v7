<?php

namespace App\Support\Controller;

use App\Entity\User;
use App\Support\Services\SupportException;
use App\Support\UseCase\SupportUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Module Assistance, lot 1 (12/11/2026, `docs/assistance.md`) : `ROLE_SUPPORT` ou `ROLE_ADMIN` (`access_control` `^/api/support`, seules routes
 * que ROLE_SUPPORT ouvre) ; réglages en écriture : `ROLE_ADMIN`. Garde du module « support » (403 MODULE_INACTIVE en mode live).
 */
#[Route('/api/support')]
class SupportController extends AbstractController
{
    public function __construct(private readonly SupportUseCase $useCase)
    {
    }

    #[Route('/tickets', name: 'support_tickets', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->useCase->list($request->query->all(), $this->agent())));
    }

    #[Route('/tickets', name: 'support_tickets_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->useCase->create($this->body($request), $this->agent()), 201));
    }

    #[Route('/tickets/{id}', name: 'support_ticket', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->useCase->detail($id)));
    }

    #[Route('/tickets/{id}', name: 'support_ticket_patch', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function patch(int $id, Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->useCase->patch($id, $this->body($request), $this->agent())));
    }

    /** JSON { body, internal?, fromCustomer? } ou multipart (body, internal, fromCustomer, attachments[]) */
    #[Route('/tickets/{id}/messages', name: 'support_ticket_message', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function message(int $id, Request $request): JsonResponse
    {
        return $this->guard(function () use ($id, $request) {
            $json = str_contains((string) $request->headers->get('Content-Type'), 'json');
            $body = $json ? $this->body($request) : $request->request->all();
            $files = $json ? [] : array_values(array_filter((array) ($request->files->all()['attachments'] ?? [])));

            return $this->json($this->useCase->message($id, $body, $files, $this->agent(), $request->getSchemeAndHttpHost()), 201);
        });
    }

    #[Route('/tickets/{id}/attachments/{attachmentId}', name: 'support_ticket_attachment', methods: ['GET'], requirements: ['id' => '\d+', 'attachmentId' => '\d+'])]
    public function attachment(int $id, int $attachmentId): Response
    {
        return $this->guard(function () use ($id, $attachmentId) {
            [$path, $name, $mime] = $this->useCase->attachment($id, $attachmentId);
            $response = new BinaryFileResponse($path, 200, ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff'], false);
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-store');
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name, 'piece-jointe');

            return $response;
        });
    }

    #[Route('/settings', name: 'support_settings', methods: ['GET'])]
    public function settings(): JsonResponse
    {
        return $this->json($this->useCase->settings());
    }

    #[Route('/settings', name: 'support_settings_save', methods: ['PUT'])]
    public function saveSettings(Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_ADMIN')) { // un compte Assistance lit les réglages, seul l'administrateur du site les change
            return $this->json(['error' => 'FORBIDDEN', 'code' => 'FORBIDDEN', 'message' => 'Réglages réservés à l\'administrateur du site.'], 403);
        }

        return $this->guard(fn () => $this->json($this->useCase->saveSettings($this->body($request), $this->getUser()?->getUserIdentifier())));
    }

    private function agent(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function body(Request $request): array
    {
        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            throw SupportException::validation(['body' => 'INVALID_JSON']);
        }

        return $body;
    }

    private function guard(callable $fn): Response
    {
        try {
            return $fn();
        } catch (SupportException $e) {
            return $this->json(['error' => $e->errorCode, 'code' => $e->errorCode, 'message' => $e->getMessage()] + $e->details, $e->status);
        }
    }
}
