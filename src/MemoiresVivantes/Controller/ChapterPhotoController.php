<?php

namespace App\MemoiresVivantes\Controller;

use App\MemoiresVivantes\Entity\ChapterPhoto;
use App\MemoiresVivantes\UseCase\UpdatePhotoUseCase;
use App\MemoiresVivantes\UseCase\DeletePhotoUseCase;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/memoires/photos')]
class ChapterPhotoController extends AbstractController
{
    public function __construct(
        private readonly UpdatePhotoUseCase $updatePhotoUseCase,
        private readonly DeletePhotoUseCase $deletePhotoUseCase,
        private readonly TenantEntityManagerProvider $emProvider
    ) {}

    #[Route('/{id}', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $photo = $em->getRepository(ChapterPhoto::class)->find(Uuid::fromString($id));
        if (!$photo) return $this->json(['error' => 'Photo not found'], 404);

        // On utilise le chapitre lié pour le voter
        $this->denyAccessUnlessGranted('CHAPTER_EDIT', $photo->getChapter());

        $data = json_decode($request->getContent(), true) ?? $request->request->all();
        $this->updatePhotoUseCase->execute($photo, $data);

        return $this->json(['status' => 'Photo updated']);
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $photo = $em->getRepository(ChapterPhoto::class)->find(Uuid::fromString($id));
        if (!$photo) return $this->json(['error' => 'Photo not found'], 404);

        $this->denyAccessUnlessGranted('CHAPTER_EDIT', $photo->getChapter());

        $this->deletePhotoUseCase->execute($photo);

        return $this->json(['status' => 'Photo deleted']);
    }
}
