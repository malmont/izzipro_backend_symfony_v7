<?php

namespace App\LossPrevention\Controller;

use App\Entity\User;
use App\Gestion\Services\GestionValidationException;
use App\LossPrevention\UseCase\LossPieceUseCase;
use App\Services\EmailConfigurationService\EmailLocale;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pièces et puces de la prévention des pertes (lot L2-a, 15/11/2026, `docs/prevention-pertes.md` § 13). `ROLE_ADMIN` (`access_control`
 * `/api/gestion`), garde de la gestion puis garde du module, comme le reste de la section. Aucune de ces routes n'écrit dans le
 * stock, les ventes ni le catalogue. Les routes `/exercices/…` jouent les mêmes gestes sur des pièces d'exercice et des puces
 * simulées ; elles sont ouvertes sans le droit du module (`LossPreventionModuleSubscriber::OPEN_ROUTES`) et ne voient jamais une
 * vraie pièce, ni l'inverse.
 */
#[Route('/api/gestion/pertes')]
class LossPieceController extends AbstractController
{
    public function __construct(private readonly LossPieceUseCase $useCase, private readonly ?EmailLocale $siteLocale = null)
    {
    }

    /** Pièces, les plus récentes d'abord (`variantId`, `productId`, `status`, `tagged`, `page`, `limit`) → 200 [fiches], en-têtes X-Total-Count, X-Page, X-Limit */
    #[Route('/pieces', name: 'gestion_pertes_pieces', methods: ['GET'])]
    public function pieces(Request $request): JsonResponse
    {
        return $this->page($this->useCase->list($request->query->all(), $this->locale($request)));
    }

    /** Crée les pièces d'une variante `{ variantId | barcode, count, commandeId? }` → 201 { pieces, count } */
    #[Route('/pieces', name: 'gestion_pertes_pieces_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->json($this->useCase->create($this->body($request), $this->locale($request), $this->userId()), 201);
    }

    /** Synthèse par variante (`variantId`, `productId`, `page`, `limit`) : stock, pièces en service, avec puce, écart → 200 { rows, total, page, limit, totals } */
    #[Route('/pieces/synthese', name: 'gestion_pertes_pieces_synthese', methods: ['GET'], priority: 1)]
    public function synthesis(Request $request): JsonResponse
    {
        return $this->json($this->useCase->synthesis($request->query->all(), $this->locale($request)));
    }

    /** Fiche d'une pièce, ses puces, ses faits → 200 ; 404 PIECE_NOT_FOUND */
    #[Route('/pieces/{id}', name: 'gestion_pertes_piece', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function piece(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->sheet($id, $this->locale($request)));
    }

    /** Retire une pièce et ses puces `{ reason, confirm: true }` → 200 { changed, piece } ; 409 CONFIRMATION_REQUIRED */
    #[Route('/pieces/{id}/retirer', name: 'gestion_pertes_piece_retirer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function retirePiece(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->retirePiece($id, $this->body($request), $this->locale($request), $this->userId()));
    }

    /** Pose une puce sur une pièce `{ pieceId | variantId | barcode, identifier, technology }` → 200 { created, associated, piece, tag } ; 409 TAG_ALREADY_ASSOCIATED { holder } */
    #[Route('/puces/associer', name: 'gestion_pertes_puces_associer', methods: ['POST'])]
    public function associate(Request $request): JsonResponse
    {
        return $this->json($this->useCase->associate($this->body($request), $this->locale($request), $this->userId()));
    }

    /** Ce que porte une puce `?identifier=` → 200 { identifier, found, refusal, tag, piece } */
    #[Route('/puces/recherche', name: 'gestion_pertes_puces_recherche', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        return $this->json($this->useCase->search($request->query->all()['identifier'] ?? null, $this->locale($request)));
    }

    /** Retire une puce `{ reason, confirm: true }` → 200 { changed, tag, piece } ; 409 CONFIRMATION_REQUIRED */
    #[Route('/puces/{id}/retirer', name: 'gestion_pertes_puce_retirer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function retireTag(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->retireTag($id, $this->body($request), $this->locale($request), $this->userId()));
    }

    // ----- Mode exercice : pièces d'exercice et puces simulées. Ces routes, et elles seules avec celles du lot EX-1, sont ouvertes
    // sans le droit du module (`LossPreventionModuleSubscriber::OPEN_ROUTES`) -----

    /** Pièces d'exercice du site → 200 { pieces, count, limit, remaining, createLimit, daily } */
    #[Route('/exercices/pieces', name: 'gestion_pertes_exercices_pieces', methods: ['GET'])]
    public function exercisePieces(Request $request): JsonResponse
    {
        return $this->json($this->useCase->exerciseList($this->locale($request)));
    }

    /** Crée des pièces d'exercice `{ variantId | barcode, count }` → 201 { pieces, count } ; 409 EXERCISE_LIMIT_REACHED, EXERCISE_DAILY_LIMIT */
    #[Route('/exercices/pieces', name: 'gestion_pertes_exercices_pieces_create', methods: ['POST'])]
    public function createExercisePieces(Request $request): JsonResponse
    {
        return $this->json($this->useCase->create($this->body($request), $this->locale($request), $this->userId(), true), 201);
    }

    /** Efface toutes les pièces d'exercice, leurs puces et leurs faits → 200 { deleted } */
    #[Route('/exercices/pieces', name: 'gestion_pertes_exercices_pieces_delete', methods: ['DELETE'])]
    public function deleteExercisePieces(): JsonResponse
    {
        return $this->json($this->useCase->deleteExercise($this->userId()));
    }

    /** Fiche d'une pièce d'exercice → 200 ; 404 PIECE_NOT_FOUND pour une vraie pièce */
    #[Route('/exercices/pieces/{id}', name: 'gestion_pertes_exercices_piece', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function exercisePiece(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->sheet($id, $this->locale($request), true));
    }

    /** Retire une pièce d'exercice `{ reason, confirm: true }` → 200 { changed, piece } */
    #[Route('/exercices/pieces/{id}/retirer', name: 'gestion_pertes_exercices_piece_retirer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function retireExercisePiece(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->retirePiece($id, $this->body($request), $this->locale($request), $this->userId(), true));
    }

    /** « Simuler une lecture » : pose une puce simulée sur une pièce d'exercice `{ pieceId | variantId | barcode, identifier? }` → 200 { created, associated, piece, tag } */
    #[Route('/exercices/puces/associer', name: 'gestion_pertes_exercices_puces_associer', methods: ['POST'])]
    public function associateExercise(Request $request): JsonResponse
    {
        return $this->json($this->useCase->associate($this->body($request), $this->locale($request), $this->userId(), true));
    }

    /** Ce que porte une puce simulée `?identifier=` → 200 { identifier, found, refusal, tag, piece } */
    #[Route('/exercices/puces/recherche', name: 'gestion_pertes_exercices_puces_recherche', methods: ['GET'])]
    public function searchExercise(Request $request): JsonResponse
    {
        return $this->json($this->useCase->search($request->query->all()['identifier'] ?? null, $this->locale($request), true));
    }

    /** Retire une puce simulée `{ reason, confirm: true }` → 200 { changed, tag, piece } */
    #[Route('/exercices/puces/{id}/retirer', name: 'gestion_pertes_exercices_puce_retirer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function retireExerciseTag(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->retireTag($id, $this->body($request), $this->locale($request), $this->userId(), true));
    }

    /** @param array{rows: list<array<string, mixed>>, total: int, page: int, limit: int} $out */
    private function page(array $out): JsonResponse
    {
        return $this->json($out['rows'], 200, ['X-Total-Count' => (string) $out['total'], 'X-Page' => (string) $out['page'], 'X-Limit' => (string) $out['limit']]);
    }

    private function userId(): int
    {
        $user = $this->getUser();
        if (!$user instanceof User || $user->getId() === null) {
            throw $this->createAccessDeniedException();
        }

        return (int) $user->getId();
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            throw new GestionValidationException(['body' => 'INVALID_JSON']);
        }

        return $body;
    }

    private function locale(Request $request): string
    {
        $asked = $request->query->get('locale');
        if (is_string($asked) && in_array(strtolower($asked), ['fr', 'en'], true)) {
            return strtolower($asked);
        }

        return $this->siteLocale?->language(null) ?? 'fr';
    }
}
