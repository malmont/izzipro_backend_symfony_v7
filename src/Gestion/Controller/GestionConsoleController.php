<?php

namespace App\Gestion\Controller;

use App\Gestion\UseCase\GestionConsoleUseCase;
use App\Services\LandingAiService\LandingAiException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Console IA générale de la gestion (lot W2, 13/11/2026, `docs/console-gestion.md` § 5). `ROLE_ADMIN` par `access_control`
 * (`/api/gestion`). La question rend tout de suite une réponse « en préparation » (202) ; l'application relit l'échange toutes les 3 s.
 */
#[Route('/api/gestion/console')]
class GestionConsoleController extends AbstractController
{
    public function __construct(private readonly GestionConsoleUseCase $useCase)
    {
    }

    /** `{ text, conversationId?, context? }` → 202 `{ conversationId, questionMessageId, messageId, state: "pending", … }` */
    #[Route('/messages', name: 'gestion_console_ask', methods: ['POST'])]
    public function ask(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->json(['error' => 'INVALID_JSON', 'code' => 'INVALID_JSON', 'message' => 'Corps JSON invalide'], Response::HTTP_BAD_REQUEST);
        }
        try {
            return $this->json($this->useCase->ask($body, self::locale($body['locale'] ?? $request->query->get('locale')), $this->getUser()?->getUserIdentifier(), $this->isGranted('ROLE_SUPER_ADMIN')), Response::HTTP_ACCEPTED);
        } catch (LandingAiException $e) {
            $code = match ($e->getStatusCode()) { 402 => 'AI_CREDITS_EXHAUSTED', 403 => 'AI_NOT_INCLUDED', 429 => 'AI_TOO_MANY_FAILURES', default => 'AI_UNAVAILABLE' };

            return $this->json(['error' => $code, 'code' => $code, 'message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    /** L'historique du site, du plus récemment modifié au plus ancien ; `?page=1&limit=20`, en-têtes `X-Total-Count`, `X-Page`, `X-Limit`. */
    #[Route('/conversations', name: 'gestion_console_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = max(1, min(100, $request->query->getInt('limit', 20)));
        [$rows, $total] = $this->useCase->list($page, $limit);

        return $this->json($rows, Response::HTTP_OK, ['X-Total-Count' => (string) $total, 'X-Page' => (string) $page, 'X-Limit' => (string) $limit]);
    }

    /** L'échange : questions et réponses, à relire toutes les 3 s tant que `pending` est vrai. */
    #[Route('/conversations/{id}', name: 'gestion_console_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id): JsonResponse
    {
        return $this->json($this->useCase->conversation($id));
    }

    /** Le gérant efface un échange (204) ; inscrit au journal. */
    #[Route('/conversations/{id}', name: 'gestion_console_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $this->useCase->delete($id);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /** Le catalogue des analyses : clé, titre, paramètres, source. */
    #[Route('/analyses', name: 'gestion_console_analyses', methods: ['GET'])]
    public function analyses(Request $request): JsonResponse
    {
        return $this->json($this->useCase->analyses(self::locale($request->query->get('locale'))));
    }

    private static function locale(mixed $asked): string
    {
        return is_string($asked) && strtolower($asked) === 'en' ? 'en' : 'fr';
    }
}
