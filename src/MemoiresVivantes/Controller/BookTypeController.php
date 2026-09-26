<?php

namespace App\MemoiresVivantes\Controller;

use App\MemoiresVivantes\BookType\BookTypeNormalizer;
use App\MemoiresVivantes\Entity\BookType;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Types de livre disponibles pour le parcours client (sans les consignes IA).
 */
#[Route('/api/memoires/book-types')]
class BookTypeController extends AbstractController
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly BookTypeNormalizer $normalizer
    ) {}

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $types = $this->emProvider->getEntityManager()->getRepository(BookType::class)->findAllOrdered();

        return $this->json(array_map(fn (BookType $t) => $this->normalizer->toPublicArray($t), $types));
    }

    #[Route('/{code}', methods: ['GET'], requirements: ['code' => '[a-z][a-z0-9_]*'])]
    public function get(string $code): JsonResponse
    {
        $type = $this->emProvider->getEntityManager()->getRepository(BookType::class)->findOneBy(['code' => $code, 'isActive' => true]);
        if (!$type) {
            return $this->json(['error' => 'Type de livre introuvable.'], 404);
        }

        return $this->json($this->normalizer->toPublicArray($type));
    }
}
