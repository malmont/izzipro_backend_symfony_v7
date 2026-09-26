<?php

namespace App\MemoiresVivantes\Controller\Admin;

use App\MemoiresVivantes\BookType\BookTypeAdminException;
use App\MemoiresVivantes\BookType\BookTypeAdminService;
use App\MemoiresVivantes\BookType\BookTypeNormalizer;
use App\MemoiresVivantes\BookType\BookTypePromptAssistant;
use App\MemoiresVivantes\BookType\DatabasePromptEngine;
use App\MemoiresVivantes\Entity\BookType;
use App\MemoiresVivantes\Entity\BookTypeChapter;
use App\MemoiresVivantes\Entity\BookTypeRole;
use App\MemoiresVivantes\Entity\MemoireQuestion;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Administration des types de livre : types, chapitres, rôles de contributeurs et questions.
 * Chaque modification renvoie le détail complet du type concerné ({ "bookType": ... }).
 */
#[Route('/api/memoires/admin', requirements: ['id' => '\d+'])]
#[IsGranted('ROLE_ADMIN')]
class BookTypeAdminController extends AbstractController
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly BookTypeAdminService $service,
        private readonly BookTypeNormalizer $normalizer,
        private readonly BookTypePromptAssistant $assistant
    ) {}

    // ---------------------------------------------------------------- Types

    #[Route('/book-types', methods: ['GET'])]
    public function listTypes(): JsonResponse
    {
        $types = $this->emProvider->getEntityManager()->getRepository(BookType::class)->findAllOrdered(false);

        return $this->json(array_map(fn (BookType $t) => $this->normalizer->toAdminSummaryArray($t), $types));
    }

    #[Route('/book-types', methods: ['POST'])]
    public function createType(Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->service->createType($this->payload($request)), 201);
    }

    #[Route('/book-types/{id}', methods: ['GET'])]
    public function getType(int $id): JsonResponse
    {
        $type = $this->find(BookType::class, $id);
        if (!$type) {
            return $this->notFound('Book type');
        }

        return $this->json(['bookType' => $this->normalizer->toAdminArray($type)]);
    }

    #[Route('/book-types/{id}', methods: ['PUT', 'PATCH'])]
    public function updateType(int $id, Request $request): JsonResponse
    {
        $type = $this->find(BookType::class, $id);
        if (!$type) {
            return $this->notFound('Book type');
        }

        return $this->handle(fn () => $this->service->updateType($type, $this->payload($request)));
    }

    #[Route('/book-types/{id}', methods: ['DELETE'])]
    public function deleteType(int $id): JsonResponse
    {
        $type = $this->find(BookType::class, $id);
        if (!$type) {
            return $this->notFound('Book type');
        }

        try {
            $this->service->deleteType($type);
        } catch (BookTypeAdminException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
        }

        return $this->json(['deleted' => true]);
    }

    // ---------------------------------------------------------------- Chapitres

    #[Route('/book-types/{id}/chapters', methods: ['POST'])]
    public function addChapter(int $id, Request $request): JsonResponse
    {
        $type = $this->find(BookType::class, $id);
        if (!$type) {
            return $this->notFound('Book type');
        }

        return $this->handle(fn () => $this->service->addChapter($type, $this->payload($request))->getBookType(), 201);
    }

    #[Route('/book-types/{id}/chapters/order', methods: ['PUT'])]
    public function reorderChapters(int $id, Request $request): JsonResponse
    {
        $type = $this->find(BookType::class, $id);
        if (!$type) {
            return $this->notFound('Book type');
        }

        return $this->handle(function () use ($type, $request) {
            $this->service->reorderChapters($type, (array) ($this->payload($request)['ids'] ?? []));
            return $type;
        });
    }

    #[Route('/book-type-chapters/{id}', methods: ['PUT', 'PATCH'])]
    public function updateChapter(int $id, Request $request): JsonResponse
    {
        $chapter = $this->find(BookTypeChapter::class, $id);
        if (!$chapter) {
            return $this->notFound('Chapter');
        }

        return $this->handle(fn () => $this->service->updateChapter($chapter, $this->payload($request))->getBookType());
    }

    #[Route('/book-type-chapters/{id}', methods: ['DELETE'])]
    public function deleteChapter(int $id): JsonResponse
    {
        $chapter = $this->find(BookTypeChapter::class, $id);
        if (!$chapter) {
            return $this->notFound('Chapter');
        }

        $type = $chapter->getBookType();
        return $this->handle(function () use ($chapter, $type) {
            $this->service->deleteChapter($chapter);
            return $type;
        });
    }

    // ---------------------------------------------------------------- Rôles

    #[Route('/book-types/{id}/roles', methods: ['POST'])]
    public function addRole(int $id, Request $request): JsonResponse
    {
        $type = $this->find(BookType::class, $id);
        if (!$type) {
            return $this->notFound('Book type');
        }

        return $this->handle(fn () => $this->service->addRole($type, $this->payload($request))->getBookType(), 201);
    }

    #[Route('/book-type-roles/{id}', methods: ['PUT', 'PATCH'])]
    public function updateRole(int $id, Request $request): JsonResponse
    {
        $role = $this->find(BookTypeRole::class, $id);
        if (!$role) {
            return $this->notFound('Role');
        }

        return $this->handle(fn () => $this->service->updateRole($role, $this->payload($request))->getBookType());
    }

    #[Route('/book-type-roles/{id}', methods: ['DELETE'])]
    public function deleteRole(int $id): JsonResponse
    {
        $role = $this->find(BookTypeRole::class, $id);
        if (!$role) {
            return $this->notFound('Role');
        }

        $type = $role->getBookType();
        return $this->handle(function () use ($role, $type) {
            $this->service->deleteRole($role);
            return $type;
        });
    }

    // ---------------------------------------------------------------- Questions

    #[Route('/book-type-chapters/{id}/questions', methods: ['POST'])]
    public function addQuestion(int $id, Request $request): JsonResponse
    {
        $chapter = $this->find(BookTypeChapter::class, $id);
        if (!$chapter) {
            return $this->notFound('Chapter');
        }

        return $this->handle(function () use ($chapter, $request) {
            $this->service->addQuestion($chapter, $this->payload($request));
            return $chapter->getBookType();
        }, 201);
    }

    #[Route('/book-type-chapters/{id}/questions/order', methods: ['PUT'])]
    public function reorderQuestions(int $id, Request $request): JsonResponse
    {
        $chapter = $this->find(BookTypeChapter::class, $id);
        if (!$chapter) {
            return $this->notFound('Chapter');
        }

        return $this->handle(function () use ($chapter, $request) {
            $this->service->reorderQuestions($chapter, (array) ($this->payload($request)['ids'] ?? []));
            return $chapter->getBookType();
        });
    }

    #[Route('/questions/{id}', methods: ['PUT', 'PATCH'])]
    public function updateQuestion(int $id, Request $request): JsonResponse
    {
        $question = $this->find(MemoireQuestion::class, $id);
        if (!$question) {
            return $this->notFound('Question');
        }

        return $this->handle(function () use ($question, $request) {
            $this->service->updateQuestion($question, $this->payload($request));
            return $this->service->typeOfQuestion($question);
        });
    }

    /**
     * Supprime la question, ou l'archive si des livres y ont déjà répondu ("result": "deleted" | "archived").
     */
    #[Route('/questions/{id}', methods: ['DELETE'])]
    public function deleteQuestion(int $id): JsonResponse
    {
        $question = $this->find(MemoireQuestion::class, $id);
        if (!$question) {
            return $this->notFound('Question');
        }

        try {
            $type = $this->service->typeOfQuestion($question);
            $result = $this->service->deleteQuestion($question);
        } catch (BookTypeAdminException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
        }

        return $this->json(['result' => $result, 'bookType' => $this->normalizer->toAdminArray($type)]);
    }

    // ---------------------------------------------------------------- Consignes IA

    /** Variables utilisables dans les consignes, pour l'aide à la saisie */
    #[Route('/book-types/prompt-variables', methods: ['GET'])]
    public function promptVariables(): JsonResponse
    {
        return $this->json(array_map(
            fn ($name, $description) => ['name' => $name, 'description' => $description],
            array_keys(DatabasePromptEngine::VARIABLES),
            DatabasePromptEngine::VARIABLES
        ));
    }

    /**
     * Propose une consigne générale améliorée par l'IA ({ "suggestion": ... }), sans l'enregistrer.
     * Corps : { "promptRaw"?: string (défaut : consigne brute enregistrée), "model"?: string }
     */
    #[Route('/book-types/{id}/prompt/optimize', methods: ['POST'])]
    public function optimizeTypePrompt(int $id, Request $request): JsonResponse
    {
        $type = $this->find(BookType::class, $id);
        if (!$type) {
            return $this->notFound('Book type');
        }
        $data = $this->payload($request);

        try {
            $suggestion = $this->assistant->optimize($type, null, (string) ($data['promptRaw'] ?? $type->getPromptRaw() ?? ''), $data['model'] ?? null);
        } catch (BookTypeAdminException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
        }

        return $this->json(['suggestion' => $suggestion]);
    }

    /** Idem pour la consigne propre à un chapitre */
    #[Route('/book-type-chapters/{id}/prompt/optimize', methods: ['POST'])]
    public function optimizeChapterPrompt(int $id, Request $request): JsonResponse
    {
        $chapter = $this->find(BookTypeChapter::class, $id);
        if (!$chapter) {
            return $this->notFound('Chapter');
        }
        $data = $this->payload($request);

        try {
            $suggestion = $this->assistant->optimize($chapter->getBookType(), $chapter, (string) ($data['promptRaw'] ?? $chapter->getPromptRaw() ?? ''), $data['model'] ?? null);
        } catch (BookTypeAdminException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
        }

        return $this->json(['suggestion' => $suggestion]);
    }

    /**
     * Teste les consignes en base du type sur un livre fictif : renvoie le prompt assemblé et un extrait généré.
     * Corps : { "chapterCode": string, "answers"?: [{question, answer}], "tone"?, "model"?, "subjectsAbsent"?: bool, "dryRun"?: bool }
     * dryRun : renvoie seulement le prompt assemblé, sans appel à l'IA.
     */
    #[Route('/book-types/{id}/test', methods: ['POST'])]
    public function testType(int $id, Request $request): JsonResponse
    {
        $type = $this->find(BookType::class, $id);
        if (!$type) {
            return $this->notFound('Book type');
        }

        try {
            return $this->json($this->assistant->test($type, $this->payload($request)));
        } catch (BookTypeAdminException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
        }
    }

    // ---------------------------------------------------------------- Outils

    /**
     * Exécute une modification et renvoie le détail du type, ou l'erreur métier.
     */
    private function handle(callable $action, int $status = 200): JsonResponse
    {
        try {
            /** @var BookType $type */
            $type = $action();
        } catch (BookTypeAdminException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
        }

        return $this->json(['bookType' => $this->normalizer->toAdminArray($type)], $status);
    }

    private function payload(Request $request): array
    {
        $data = json_decode($request->getContent(), true);
        return is_array($data) ? $data : [];
    }

    private function find(string $class, int $id): ?object
    {
        return $this->emProvider->getEntityManager()->getRepository($class)->find($id);
    }

    private function notFound(string $what): JsonResponse
    {
        $labels = [
            'Book type' => 'Type de livre introuvable.',
            'Chapter' => 'Chapitre introuvable.',
            'Role' => 'Rôle introuvable.',
            'Question' => 'Question introuvable.',
        ];

        return $this->json(['error' => $labels[$what] ?? 'Élément introuvable.'], 404);
    }
}
