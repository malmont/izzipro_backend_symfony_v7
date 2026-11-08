<?php

namespace App\MemoiresVivantes\Controller;

use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Entity\ChapterInvitation;
use App\MemoiresVivantes\Exception\ChapterInvitationException;
use App\MemoiresVivantes\Security\BookAccessGuard;
use App\MemoiresVivantes\Security\BookVoter;
use App\MemoiresVivantes\Security\ChapterVoter;
use App\MemoiresVivantes\Services\ChapterInvitationService;
use App\MemoiresVivantes\UseCase\Invitation\CancelChapterInvitationUseCase;
use App\MemoiresVivantes\UseCase\Invitation\CompleteChapterInvitationUseCase;
use App\MemoiresVivantes\UseCase\Invitation\CreateChapterInvitationUseCase;
use App\MemoiresVivantes\UseCase\Invitation\ListBookInvitationsUseCase;
use App\MemoiresVivantes\UseCase\Invitation\ResendChapterInvitationUseCase;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Invitations par e-mail des chapitres (08/11/2026). Création, suivi, renvoi et arrêt : compte connecté avec le droit
 * d'édition (propriétaire du livre ou administrateur ; access_control exige la connexion, le voter le droit). Fin par la
 * personne invitée : lien signé (BookAccessGuard::canView). Désinscription : lien public de l'e-mail, qui porte le code
 * du site. Erreurs : {error, code, params?} (docs/memoires-vivantes.md, « Invitations par e-mail »).
 */
#[Route('/api/memoires')]
class ChapterInvitationController extends AbstractController
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionManager $tenants,
        private readonly BookAccessGuard $accessGuard,
        private readonly ChapterInvitationService $invitations,
        private readonly CreateChapterInvitationUseCase $create,
        private readonly ListBookInvitationsUseCase $list,
        private readonly ResendChapterInvitationUseCase $resend,
        private readonly CancelChapterInvitationUseCase $cancel,
        private readonly CompleteChapterInvitationUseCase $complete
    ) {
    }

    #[Route('/chapters/{id}/invitations', methods: ['POST'])]
    public function create(string $id, Request $request): JsonResponse
    {
        $chapter = $this->chapter($id);
        if ($chapter === null) {
            return $this->json(['error' => 'Chapitre introuvable.', 'code' => 'NOT_FOUND'], Response::HTTP_NOT_FOUND);
        }
        if ($denied = $this->denyUnlessEditor($chapter)) {
            return $denied;
        }

        try {
            return $this->json($this->create->execute($chapter, json_decode((string) $request->getContent(), true) ?: [], $request), Response::HTTP_CREATED);
        } catch (ChapterInvitationException $e) {
            return $this->json($e->body(), $e->status);
        }
    }

    #[Route('/books/{id}/invitations', methods: ['GET'])]
    public function listForBook(string $id): JsonResponse
    {
        try {
            $book = $this->emProvider->getEntityManager()->getRepository(Book::class)->find(Uuid::fromString($id));
        } catch (\Throwable) {
            $book = null;
        }
        if ($book === null) {
            return $this->json(['error' => 'Livre introuvable.', 'code' => 'NOT_FOUND'], Response::HTTP_NOT_FOUND);
        }
        if ($this->getUser() === null) {
            return $this->json(['error' => 'Authentification requise.', 'code' => 'UNAUTHENTICATED'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->isGranted(BookVoter::EDIT, $book)) {
            return $this->json(['error' => 'Accès refusé à ce livre.', 'code' => 'FORBIDDEN'], Response::HTTP_FORBIDDEN);
        }

        return $this->json($this->list->execute($book));
    }

    #[Route('/invitations/{id}/resend', methods: ['POST'])]
    public function resend(string $id, Request $request): JsonResponse
    {
        $invitation = $this->invitation($id);
        if ($invitation === null) {
            return $this->json(['error' => 'Invitation introuvable.', 'code' => 'INVITATION_NOT_FOUND'], Response::HTTP_NOT_FOUND);
        }
        if ($denied = $this->denyUnlessEditor($invitation->getChapter())) {
            return $denied;
        }

        try {
            return $this->json($this->resend->execute($invitation, $request));
        } catch (ChapterInvitationException $e) {
            return $this->json($e->body(), $e->status);
        }
    }

    #[Route('/invitations/{id}', methods: ['DELETE'])]
    public function cancel(string $id): JsonResponse
    {
        $invitation = $this->invitation($id);
        if ($invitation === null) {
            return $this->json(['error' => 'Invitation introuvable.', 'code' => 'INVITATION_NOT_FOUND'], Response::HTTP_NOT_FOUND);
        }
        if ($denied = $this->denyUnlessEditor($invitation->getChapter())) {
            return $denied;
        }

        return $this->json($this->cancel->execute($invitation));
    }

    /** La personne invitée a terminé : paramètres du lien signé (expires, signature, chapterId, contributorId) ou propriétaire connecté */
    #[Route('/chapters/{id}/invitation/complete', methods: ['POST'])]
    public function complete(string $id, Request $request): JsonResponse
    {
        $chapter = $this->chapter($id);
        if ($chapter === null) {
            return $this->json(['error' => 'Chapitre introuvable.', 'code' => 'NOT_FOUND'], Response::HTTP_NOT_FOUND);
        }
        if ($denied = $this->accessGuard->canView($chapter->getBook(), $request)) {
            return $this->json($this->accessGuard->deniedBody($denied), $denied[0]);
        }
        $contributorId = $request->attributes->get('validatedContributorId');

        return $this->json($this->complete->execute($chapter, $contributorId === null ? null : (string) $contributorId));
    }

    /** Lien « ne plus recevoir » de l'e-mail : public, le code du site est dans l'adresse (aucun en-tête X-Tenant-Host depuis un client de messagerie) */
    #[Route('/invitations/unsubscribe/{code}/{token}', methods: ['GET'], requirements: ['code' => '[A-Za-z0-9_-]+', 'token' => '[a-f0-9]{64}'])]
    public function unsubscribe(string $code, string $token): Response
    {
        $invitation = null;
        $tenant = $this->tenants->findTenantByCode($code);
        if ($tenant !== null && !empty($tenant['dbname'])) {
            $this->emProvider->switchTenant((string) $tenant['dbname'], $code);
            $invitation = $this->invitations->unsubscribe($token);
        }

        return new Response(
            $this->renderView('memoires/invitation_unsubscribed.html.twig', ['invitation' => $invitation, 'locale' => $invitation?->getLocale() ?? 'fr']),
            $invitation === null ? Response::HTTP_NOT_FOUND : Response::HTTP_OK
        );
    }

    private function denyUnlessEditor(?Chapter $chapter): ?JsonResponse
    {
        if ($this->getUser() === null) {
            return $this->json(['error' => 'Authentification requise.', 'code' => 'UNAUTHENTICATED'], Response::HTTP_UNAUTHORIZED);
        }
        if ($chapter === null || !$this->isGranted(ChapterVoter::EDIT, $chapter)) {
            return $this->json(['error' => 'Accès refusé à ce livre.', 'code' => 'FORBIDDEN'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function chapter(string $id): ?Chapter
    {
        try {
            return $this->emProvider->getEntityManager()->getRepository(Chapter::class)->find(Uuid::fromString($id));
        } catch (\Throwable) {
            return null;
        }
    }

    private function invitation(string $id): ?ChapterInvitation
    {
        try {
            return $this->emProvider->getEntityManager()->getRepository(ChapterInvitation::class)->find(Uuid::fromString($id));
        } catch (\Throwable) {
            return null;
        }
    }
}
