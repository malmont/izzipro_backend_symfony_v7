<?php

namespace App\Gestion\Controller;

use App\Gestion\Services\GestionValidationException;
use App\Gestion\Services\Report\GestionReportService;
use App\Services\LandingAiService\LandingAiException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Rapports PDF de la gestion (lot DOC-1, 15/11/2026, `docs/rapports-gestion.md`). Droits : ceux de toute route `/api/gestion`
 * (`ROLE_ADMIN`, module de gestion actif). Un fichier ne se lit jamais par un lien public : le jeton du gérant est exigé.
 */
#[Route('/api/gestion/rapports')]
class GestionReportController extends AbstractController
{
    public function __construct(private readonly GestionReportService $reports)
    {
    }

    /** Les rapports du site, du plus récent au plus ancien → 200 { reports, page, pages, total } (20 par page ; `?kind=`) */
    #[Route('', name: 'gestion_rapports_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $kind = $request->query->get('kind');

        return $this->json($this->reports->list(max(1, $request->query->getInt('page', 1)), is_string($kind) && $kind !== '' ? $kind : null, self::locale($request->query->get('locale'))));
    }

    /** Demande un rapport { kind: "console.answer", messageId, locale? } → 202 en préparation, 200 si le fichier de la même réponse est déjà prêt */
    #[Route('', name: 'gestion_rapports_request', methods: ['POST'])]
    public function request(Request $request): JsonResponse
    {
        $content = trim((string) $request->getContent());
        $body = $content === '' ? [] : json_decode($content, true);
        if (!is_array($body)) {
            throw new GestionValidationException(['body' => 'INVALID_JSON']);
        }
        try {
            [$report, $status] = $this->reports->request($body, self::locale($request->query->get('locale')), $this->getUser()?->getUserIdentifier(), $this->isGranted('ROLE_SUPER_ADMIN'));

            return $this->json($report, $status);
        } catch (LandingAiException $e) {
            $code = match ($e->getStatusCode()) { 402 => 'AI_CREDITS_EXHAUSTED', 403 => 'AI_NOT_INCLUDED', 429 => 'AI_TOO_MANY_FAILURES', default => 'AI_UNAVAILABLE' };

            $message = $code === 'AI_NOT_INCLUDED' ? 'Le rapport d\'une réponse de la console demande le palier d\'IA de votre site.' : $e->getMessage();

            return $this->json(['error' => $code, 'code' => $code, 'message' => $message], $e->getStatusCode());
        }
    }

    /** Un rapport : état, titre, pages, taille, lien de téléchargement → 200 (à relire toutes les 3 secondes tant que `state` vaut `pending`) */
    #[Route('/{id}', name: 'gestion_rapports_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, Request $request): JsonResponse
    {
        return $this->json($this->reports->show($id, self::locale($request->query->get('locale'))));
    }

    /** Le fichier PDF d'un rapport prêt → 200 application/pdf ; 409 REPORT_NOT_READY, REPORT_FAILED_NO_FILE ; 404 REPORT_NOT_FOUND */
    #[Route('/{id}/fichier', name: 'gestion_rapports_file', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function download(int $id): Response
    {
        $file = $this->reports->file($id);
        $response = new BinaryFileResponse($file['path']);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file['filename']);

        return $response;
    }

    /** Efface un rapport et son fichier → 204 ; 409 REPORT_NOT_READY tant qu'il est en préparation */
    #[Route('/{id}', name: 'gestion_rapports_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): Response
    {
        $this->reports->delete($id, $this->getUser()?->getUserIdentifier());

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private static function locale(mixed $asked): string
    {
        return is_string($asked) && strtolower($asked) === 'en' ? 'en' : 'fr';
    }
}
