<?php

namespace App\LossPrevention\Controller;

use App\Entity\User;
use App\Gestion\Services\GestionValidationException;
use App\LossPrevention\UseCase\LossInstallationUseCase;
use App\Services\EmailConfigurationService\EmailLocale;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Étiquettes des pièces, assistant d'installation et gamme de matériel (lot L2-b, 15/11/2026, `docs/prevention-pertes.md` § 13.10).
 * `ROLE_ADMIN` (`access_control` `/api/gestion`), garde de la gestion puis du module. Les routes `/exercices/…` jouent les mêmes
 * gestes sur des pièces d'exercice et des puces simulées ; elles sont ouvertes sans le droit du module
 * (`LossPreventionModuleSubscriber::OPEN_ROUTES`).
 */
#[Route('/api/gestion/pertes')]
class LossInstallationController extends AbstractController
{
    public function __construct(private readonly LossInstallationUseCase $useCase, private readonly ?EmailLocale $siteLocale = null)
    {
    }

    /** Étiquettes de pièces `{ pieceIds, format?, showPrice? }` → 200 application/pdf, en-têtes X-Labels-Count et X-Labels-Format ; une lecture */
    #[Route('/pieces/etiquettes/pdf', name: 'gestion_pertes_pieces_etiquettes_pdf', methods: ['POST'], priority: 1)]
    public function pieceLabels(Request $request): Response
    {
        return $this->pdf($this->useCase->pieceLabels($this->body($request), $this->locale($request)), 'etiquettes-pieces.pdf');
    }

    /** Assistant d'installation : étapes calculées depuis les données → 200 { steps, progress, complete, exercise, screen } ; n'écrit rien */
    #[Route('/installation', name: 'gestion_pertes_installation', methods: ['GET'])]
    public function installation(Request $request): JsonResponse
    {
        return $this->json($this->useCase->steps($this->locale($request)));
    }

    /** « Vérifier » une étape → 200 { code, verified, hint } ; 404 STEP_NOT_FOUND ; n'écrit rien */
    #[Route('/installation/etapes/{code}/verifier', name: 'gestion_pertes_installation_verifier', methods: ['POST'], requirements: ['code' => '[a-z_]{1,30}'])]
    public function verify(string $code, Request $request): JsonResponse
    {
        return $this->json($this->useCase->verify($code, $this->locale($request)));
    }

    /** Lire une puce d'essai `{ identifier, technology }` → 200 { ok, identifier, technology, refusal, hint, tag, installation } */
    #[Route('/installation/puce-test', name: 'gestion_pertes_installation_puce_test', methods: ['POST'])]
    public function testRead(Request $request): JsonResponse
    {
        return $this->json($this->useCase->testRead($this->body($request), $this->locale($request), $this->userId()));
    }

    /** Étiquette d'essai `{ format? }` → 200 application/pdf */
    #[Route('/installation/etiquette-test', name: 'gestion_pertes_installation_etiquette_test', methods: ['POST'])]
    public function testLabel(Request $request): Response
    {
        return $this->pdf($this->useCase->testLabel($this->body($request), $this->locale($request), $this->userId()) + ['count' => 1], 'etiquette-essai.pdf');
    }

    /** Gamme de matériel : besoins et niveaux → 200 { needs, levels, note, screen } */
    #[Route('/materiel', name: 'gestion_pertes_materiel', methods: ['GET'])]
    public function hardware(Request $request): JsonResponse
    {
        return $this->json($this->useCase->hardware($this->locale($request)));
    }

    // ----- Mode exercice : les mêmes routes, ouvertes sans le droit du module (`LossPreventionModuleSubscriber::OPEN_ROUTES`) -----

    #[Route('/exercices/pieces/etiquettes/pdf', name: 'gestion_pertes_exercices_pieces_etiquettes_pdf', methods: ['POST'], priority: 1)]
    public function exercisePieceLabels(Request $request): Response
    {
        return $this->pdf($this->useCase->pieceLabels($this->body($request), $this->locale($request), true), 'etiquettes-exercice.pdf');
    }

    #[Route('/exercices/installation', name: 'gestion_pertes_exercices_installation', methods: ['GET'])]
    public function exerciseInstallation(Request $request): JsonResponse
    {
        return $this->json($this->useCase->steps($this->locale($request), true));
    }

    #[Route('/exercices/installation/etapes/{code}/verifier', name: 'gestion_pertes_exercices_installation_verifier', methods: ['POST'], requirements: ['code' => '[a-z_]{1,30}'])]
    public function exerciseVerify(string $code, Request $request): JsonResponse
    {
        return $this->json($this->useCase->verify($code, $this->locale($request), true));
    }

    #[Route('/exercices/installation/puce-test', name: 'gestion_pertes_exercices_installation_puce_test', methods: ['POST'])]
    public function exerciseTestRead(Request $request): JsonResponse
    {
        return $this->json($this->useCase->testRead($this->body($request), $this->locale($request), $this->userId(), true));
    }

    #[Route('/exercices/installation/etiquette-test', name: 'gestion_pertes_exercices_installation_etiquette_test', methods: ['POST'])]
    public function exerciseTestLabel(Request $request): Response
    {
        return $this->pdf($this->useCase->testLabel($this->body($request), $this->locale($request), $this->userId(), true) + ['count' => 1], 'etiquette-essai.pdf');
    }

    #[Route('/exercices/materiel', name: 'gestion_pertes_exercices_materiel', methods: ['GET'])]
    public function exerciseHardware(Request $request): JsonResponse
    {
        return $this->json($this->useCase->hardware($this->locale($request)));
    }

    /** @param array{content: string, count: int, format: string} $pdf */
    private function pdf(array $pdf, string $name): Response
    {
        return new Response($pdf['content'], Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $name),
            'X-Labels-Count' => (string) $pdf['count'],
            'X-Labels-Format' => $pdf['format'],
        ]);
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
