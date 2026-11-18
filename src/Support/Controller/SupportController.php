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

    /**
     * Lot INC-3 (18/11/2026) : « Transmettre à l'équipe technique » { summary } (20 à 2 000 caractères, écrit par l'agent, masqué) → 201
     * { platform { status: transmitted, answer: null } } ; le fil et les pièces jointes ne sont jamais copiés ; 404 TICKET_NOT_FOUND,
     * 409 ALREADY_ESCALATED (un seul signalement ouvert par ticket), 422 { fields.summary }.
     */
    #[Route('/tickets/{id}/escalade', name: 'support_ticket_escalate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function escalate(int $id, Request $request): JsonResponse
    {
        return $this->guard(function () use ($id, $request) {
            try {
                return $this->json($this->useCase->escalate($id, $this->body($request), $this->agent()), 201);
            } catch (\App\Gestion\Services\GestionValidationException $e) {
                return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => 'Résumé invalide.', 'fields' => $e->fields], 422);
            } catch (\App\Gestion\Services\GestionConflictException $e) {
                return $this->json($e->toArray(), $e->status);
            }
        });
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

    /** Lot SUPPORT-WEB : qui est connecté, et l'état du module ; un compte Assistance seul ne lit ni /api/profile ni /api/site/features */
    #[Route('/me', name: 'support_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->useCase->me($this->agent())));
    }

    /** Lot SUPPORT-WEB : les comptes à qui attribuer une demande (un nom, jamais un courriel) */
    #[Route('/agents', name: 'support_agents', methods: ['GET'])]
    public function agents(): JsonResponse
    {
        // l'administrateur du site voit aussi les invitations en attente (`pending`)
        return $this->guard(fn () => $this->json($this->isGranted('ROLE_ADMIN') ? $this->useCase->agentsForAdmin($this->agent()) : $this->useCase->agents($this->agent())));
    }

    /** Lot SUPPORT-WEB : l'administrateur du site invite un agent d'assistance (compte ROLE_SUPPORT seul) ; droit du module exigé */
    #[Route('/agents', name: 'support_agent_invite', methods: ['POST'])]
    public function inviteAgent(Request $request): JsonResponse
    {
        return $this->adminOnly() ?? $this->guard(fn () => $this->json($this->useCase->inviteAgent($this->body($request), $this->agent()), 201));
    }

    /** Lot SUPPORT-WEB : un nouveau lien d'invitation (l'ancien ne vaut plus) */
    #[Route('/agents/{id}/invitation', name: 'support_agent_invitation', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resendInvitation(int $id, Request $request): JsonResponse
    {
        $locale = $request->query->get('locale');

        return $this->adminOnly() ?? $this->guard(fn () => $this->json($this->useCase->resendInvitation($id, $this->agent(), is_string($locale) ? $locale : null)));
    }

    /** Lot SUPPORT-WEB : l'agent perd l'accès (son compte et ses messages restent) */
    #[Route('/agents/{id}', name: 'support_agent_revoke', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function revokeAgent(int $id): JsonResponse
    {
        return $this->adminOnly() ?? $this->guard(fn () => $this->json($this->useCase->revokeAgent($id)));
    }

    private function adminOnly(): ?JsonResponse
    {
        return $this->isGranted('ROLE_ADMIN') ? null : $this->json(['error' => 'FORBIDDEN', 'code' => 'FORBIDDEN', 'message' => 'Réservé à l\'administrateur du site.'], 403);
    }

    /** Lot SUPPORT-WEB : une commande par sa référence exacte, pour remplir le formulaire d'une demande ; jamais une recherche ni une liste */
    #[Route('/orders/lookup', name: 'support_order_lookup', methods: ['GET'])]
    public function orderLookup(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $reference = $request->query->get('reference');
            if (!is_string($reference) || trim($reference) === '') {
                throw SupportException::validation(['reference' => 'REQUIRED']);
            }

            return $this->json($this->useCase->orderByReference($reference));
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
