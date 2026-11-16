<?php

namespace App\LossPrevention\Controller;

use App\Entity\User;
use App\Gestion\Services\GestionValidationException;
use App\LossPrevention\UseCase\LossPrintUseCase;
use App\Services\EmailConfigurationService\EmailLocale;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Travaux d'impression de l'imprimante à puce, vus par l'administrateur (lot L3-c, 16/11/2026, `docs/prevention-pertes.md`
 * § 16.20). `ROLE_ADMIN` (`access_control` `/api/gestion`), garde de la gestion puis du module. Les deux lectures
 * `/exercices/impressions…` montrent les travaux de l'imprimante simulée ; elles sont ouvertes sans le droit du module
 * (`LossPreventionModuleSubscriber::OPEN_ROUTES`). Aucune de ces routes ne rend le mot de passe d'accès des puces : seul le
 * boîtier le reçoit (`LossBoxChannelController::jobs`).
 */
#[Route('/api/gestion/pertes')]
class LossPrintController extends AbstractController
{
    public function __construct(private readonly LossPrintUseCase $useCase, private readonly ?EmailLocale $siteLocale = null)
    {
    }

    /** Travaux d'impression → 200 { items, total, page, limit, printers, ready { ok, reason, message }, limits, exercise } ; `page`, `limit` (50 au plus) */
    #[Route('/impressions', name: 'gestion_pertes_impressions', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        return $this->json($this->useCase->list($request->query->all(), $this->locale($request)));
    }

    /**
     * Créer un travail `{ deviceId, pieceIds, withPrice?, confirmTagged? }` → 201 le travail et ses lignes ; 404 PRINTER_NOT_FOUND ;
     * 409 PRINT_KEY_MISSING | PRINT_KEY_INVALID | PRINT_KEY_MISMATCH, PIECES_ALREADY_QUEUED, PIECES_ALREADY_TAGGED (sans
     * `confirmTagged`), PIECE_TAG_LIMIT, PIECE_RETIRED, BARCODE_MISSING, PRINT_JOBS_LIMIT ; 422
     */
    #[Route('/impressions', name: 'gestion_pertes_impressions_creer', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->json($this->useCase->create($this->body($request), $this->locale($request), $this->userId()), 201);
    }

    /** Un travail et ses lignes → 200 ; 404 PRINT_JOB_NOT_FOUND */
    #[Route('/impressions/{id}', name: 'gestion_pertes_impression', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function sheet(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->sheet($id, $this->locale($request)));
    }

    /** Annuler un travail (ses lignes qui attendent encore) → 200 le travail et `cancelledLines` ; rejouable ; 404 PRINT_JOB_NOT_FOUND */
    #[Route('/impressions/{id}/annuler', name: 'gestion_pertes_impression_annuler', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancel(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->cancel($id, $this->locale($request), $this->userId()));
    }

    // ----- Mode exercice : les travaux de l'imprimante simulée, en lecture (les scénarios se jouent sur le boîtier simulé) -----

    /** Travaux de l'imprimante simulée → 200, même forme que `/impressions` */
    #[Route('/exercices/impressions', name: 'gestion_pertes_exercices_impressions', methods: ['GET'])]
    public function exerciseList(Request $request): JsonResponse
    {
        return $this->json($this->useCase->list($request->query->all(), $this->locale($request), true));
    }

    #[Route('/exercices/impressions/{id}', name: 'gestion_pertes_exercices_impression', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function exerciseSheet(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->sheet($id, $this->locale($request), true));
    }

    private function userId(): int
    {
        $user = $this->getUser();
        if (!$user instanceof User || $user->getId() === null) {
            throw $this->createAccessDeniedException();
        }

        return (int) $user->getId();
    }

    /** @return array<string, mixed> corps JSON ; un corps vide vaut {} */
    private function body(Request $request): array
    {
        $content = trim((string) $request->getContent());
        $body = $content === '' ? [] : json_decode($content, true);
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
