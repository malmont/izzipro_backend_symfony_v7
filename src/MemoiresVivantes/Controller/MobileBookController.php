<?php

namespace App\MemoiresVivantes\Controller;

use App\MemoiresVivantes\BookType\BookTypeNormalizer;
use App\MemoiresVivantes\BookType\BookTypeResolver;
use App\MemoiresVivantes\Dto\BookInputDto;
use App\MemoiresVivantes\Dto\BookOutputDto;
use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Exception\InvalidBookTypeException;
use App\MemoiresVivantes\UseCase\CreateMobileBookUseCase;
use App\Services\MediaUrlResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Routes propres à l'application mobile Living Memory (16/10/2026). Connexion obligatoire (access_control
 * `^/api/memoires/mobile/`). Le livre créé ici porte channel = mobile ; tout le reste (lecture, modification,
 * chapitres, PDF, administration par le web) passe par les routes existantes, sans distinction. Contrat :
 * docs/memoires-vivantes.md, « Application mobile Living Memory ».
 */
#[Route('/api/memoires/mobile/books')]
class MobileBookController extends AbstractController
{
    public function __construct(
        private readonly CreateMobileBookUseCase $createMobileBookUseCase,
        private readonly BookTypeResolver $bookTypeResolver,
        private readonly BookTypeNormalizer $bookTypeNormalizer,
        private readonly \App\MemoiresVivantes\Services\AiQuotaService $aiQuota,
        private readonly ?MediaUrlResolver $mediaUrlResolver = null
    ) {}

    /** Même corps et même réponse (201, livre complet) que POST /api/memoires/books ; type inconnu ou inactif : 422 {error: TYPE_INVALID, message (pour l'utilisateur), requestedType} */
    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $data = json_decode($request->getContent(), true);
        $dto = new BookInputDto(is_array($data) ? $data : []);
        if ($dto->title === null || trim($dto->title) === '') {
            return $this->json(['error' => 'Le titre du livre est obligatoire.'], 422);
        }
        try {
            $book = $this->createMobileBookUseCase->execute($user, $dto);
        } catch (InvalidBookTypeException $e) {
            return $this->json(['error' => 'TYPE_INVALID', 'message' => $e->getMessage(), 'requestedType' => $e->requestedType], 422);
        }
        $host = $this->mediaUrlResolver?->getPublicHost($request->getSchemeAndHttpHost()) ?? $request->getSchemeAndHttpHost();

        return $this->json(new BookOutputDto($book, $host, speakersByTheme: $this->bookTypeResolver->speakersByTheme($book), typeInfo: $this->typeInfo($book), aiQuotaLimited: $this->aiQuota->isLimited($book, $this->getUser())), 201);
    }

    /** Comme BookController::typeInfo : le type du livre, même désactivé, plus isActive */
    private function typeInfo(Book $book): ?array
    {
        $type = $this->bookTypeResolver->find($book->getType());

        return $type === null ? null : $this->bookTypeNormalizer->toPublicArray($type) + ['isActive' => $type->isActive()];
    }
}
