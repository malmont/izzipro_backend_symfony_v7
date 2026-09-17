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
            'isApproved' => $contributor->isApproved(),
            'approvedAt' => $contributor->getApprovedAt()?->format(\DateTimeInterface::ATOM),
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
        if (isset($data['isApproved'])) {
            $contributor->setIsApproved((bool)$data['isApproved']);
            if ((bool)$data['isApproved'] && !$contributor->getApprovedAt()) {
                $contributor->setApprovedAt(new \DateTimeImmutable());
            }
        }

        $em->flush();

        return $this->json([
            'id' => (string) $contributor->getId(),
            'firstName' => $contributor->getFirstName(),
            'role' => $contributor->getRole(),
            'sortOrder' => $contributor->getSortOrder(),
            'isApproved' => $contributor->isApproved(),
            'approvedAt' => $contributor->getApprovedAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $contributor->getCreatedAt()->format(\DateTimeInterface::ATOM)
        ]);
    }

    #[Route('/contributors/{id}/approve', methods: ['POST'])]
    public function approve(string $id, Request $request): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        try {
            $contributor = $em->getRepository(Contributor::class)->find(Uuid::fromString($id));
        } catch (\Throwable) {
            return $this->json(['error' => 'Invalid contributor UUID'], 400);
        }

        if (!$contributor) {
            return $this->json(['error' => 'Contributor not found'], 404);
        }

        // Autorisation : propriétaire authentifié ou lien signé
        $isAuthorized = false;
        if ($this->getUser() && $this->isGranted('BOOK_EDIT', $contributor->getBook())) {
            $isAuthorized = true;
        } else {
            $expires = $request->query->get('expires') ?? $request->request->get('expires');
            $signature = $request->query->get('signature') ?? $request->request->get('signature');
            if ($expires && $signature && time() <= (int)$expires) {
                $secret = $this->getParameter('kernel.secret');
                $expectedContrib = hash_hmac('sha256', "contributorId=" . $id . "&expires=" . $expires, $secret);
                if (hash_equals($expectedContrib, $signature)) {
                    $isAuthorized = true;
                } else {
                    $chapterId = $request->query->get('chapterId') ?? $request->request->get('chapterId');
                    if ($chapterId) {
                        $expectedFull = hash_hmac('sha256', "chapterId=" . $chapterId . "&contributorId=" . $id . "&expires=" . $expires, $secret);
                        if (hash_equals($expectedFull, $signature)) {
                            $isAuthorized = true;
                        }
                    }
                }
            }
        }

        if (!$isAuthorized) {
            return $this->json(['error' => 'Unauthorized or invalid/expired signature'], 403);
        }

        $contributor->setIsApproved(true);
        $contributor->setApprovedAt(new \DateTimeImmutable());
        $em->flush();

        return $this->json([
            'status' => 'approved',
            'id' => (string)$contributor->getId(),
            'firstName' => $contributor->getFirstName(),
            'isApproved' => true,
            'approvedAt' => $contributor->getApprovedAt()->format(\DateTimeInterface::ATOM),
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
