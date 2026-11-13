<?php

namespace App\Gestion\Controller;

use App\Gestion\Services\GestionValidationException;
use App\Gestion\UseCase\GestionLexiqueUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lexique de la gestion (lot W2-APPRENDRE, A-1 et A-3, 13/11/2026, `docs/lexique-gestion.md`). `ROLE_ADMIN` par `access_control`
 * (`/api/gestion`). Aucun appel à l'IA, aucune unité : définitions et questions sont écrites dans le dépôt, les chiffres du site
 * viennent d'une analyse nommée. `?locale=en` pour l'anglais, français sinon.
 */
#[Route('/api/gestion/lexique')]
class GestionLexiqueController extends AbstractController
{
    private const KEY = '[a-z0-9-]+';

    public function __construct(private readonly GestionLexiqueUseCase $useCase)
    {
    }

    /** `{ version, entries: [{ key, term, short, definition, synonyms, related, analysis, screens, quiz }] }` */
    #[Route('', name: 'gestion_lexique', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        return $this->json($this->useCase->list(self::locale($request)));
    }

    /** L'entrée et `site` : les chiffres du site pour la notion, avec une phrase d'illustration écrite par le serveur. 404 `LEXIQUE_TERM_NOT_FOUND`. */
    #[Route('/{key}', name: 'gestion_lexique_entry', methods: ['GET'], requirements: ['key' => self::KEY])]
    public function entry(string $key, Request $request): JsonResponse
    {
        return $this->json($this->useCase->entry($key, self::locale($request)));
    }

    /** `{ key, term, questions: [{ id, question, choices: [{ id, label }] }] }`, sans la bonne réponse */
    #[Route('/{key}/quiz', name: 'gestion_lexique_quiz', methods: ['GET'], requirements: ['key' => self::KEY])]
    public function quiz(string $key, Request $request): JsonResponse
    {
        return $this->json($this->useCase->quiz($key, self::locale($request)));
    }

    /** `{ choice }` → `{ correct, correctChoice, explanation }` : corrigé par le serveur, rien d'enregistré */
    #[Route('/{key}/quiz/{question}', name: 'gestion_lexique_quiz_answer', methods: ['POST'], requirements: ['key' => self::KEY, 'question' => '[a-z0-9]+'])]
    public function answer(string $key, string $question, Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            throw new GestionValidationException(['body' => 'INVALID_JSON']);
        }

        return $this->json($this->useCase->answer($key, $question, $body, self::locale($request)));
    }

    private static function locale(Request $request): string
    {
        $asked = $request->query->get('locale');

        return is_string($asked) && strtolower($asked) === 'en' ? 'en' : 'fr';
    }
}
