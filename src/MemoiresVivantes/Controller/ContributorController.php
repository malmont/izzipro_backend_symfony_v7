<?php

namespace App\MemoiresVivantes\Controller;

use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\Contributor;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/memoires')]
class ContributorController extends AbstractController
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider
    ) {}

    #[Route('/books/{id}/contributors', methods: ['POST'])]
    public function create(string $id, Request $request): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $book = $em->getRepository(Book::class)->find(Uuid::fromString($id));
        if (!$book) {
            return $this->json(['error' => 'Book not found'], 404);
        }

        $this->denyAccessUnlessGranted('BOOK_EDIT', $book);

        $data = json_decode($request->getContent(), true) ?? [];
        if (empty($data['firstName']) || empty($data['role'])) {
            return $this->json(['error' => 'Missing firstName or role'], 400);
        }

        $contributor = new Contributor();
        $contributor->setBook($book);
        $contributor->setFirstName($data['firstName']);
        $contributor->setRole($data['role']);
        $contributor->setSortOrder($data['sortOrder'] ?? 0);

        $em->persist($contributor);
        $em->flush();

        return $this->json([
            'id' => (string) $contributor->getId(),
            'firstName' => $contributor->getFirstName(),
            'role' => $contributor->getRole(),
            'sortOrder' => $contributor->getSortOrder(),
            'createdAt' => $contributor->getCreatedAt()->format(\DateTimeInterface::ATOM)
        ], 201);
    }

    #[Route('/contributors/{id}', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $contributor = $em->getRepository(Contributor::class)->find(Uuid::fromString($id));
        if (!$contributor) {
            return $this->json(['error' => 'Contributor not found'], 404);
        }

        $this->denyAccessUnlessGranted('BOOK_EDIT', $contributor->getBook());

        $data = json_decode($request->getContent(), true) ?? [];
        if (isset($data['firstName'])) {
            $contributor->setFirstName($data['firstName']);
        }
        if (isset($data['role'])) {
            $contributor->setRole($data['role']);
        }
        if (isset($data['sortOrder'])) {
            $contributor->setSortOrder((int)$data['sortOrder']);
        }

        $em->flush();

        return $this->json([
            'id' => (string) $contributor->getId(),
            'firstName' => $contributor->getFirstName(),
            'role' => $contributor->getRole(),
            'sortOrder' => $contributor->getSortOrder(),
            'createdAt' => $contributor->getCreatedAt()->format(\DateTimeInterface::ATOM)
        ]);
    }

    #[Route('/contributors/{id}', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $contributor = $em->getRepository(Contributor::class)->find(Uuid::fromString($id));
        if (!$contributor) {
            return $this->json(['error' => 'Contributor not found'], 404);
        }

        $this->denyAccessUnlessGranted('BOOK_EDIT', $contributor->getBook());

        $em->remove($contributor);
        $em->flush();

        return $this->json(['status' => 'Contributor deleted']);
    }
}
