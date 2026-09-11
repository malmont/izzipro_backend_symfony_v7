<?php

namespace App\MemoiresVivantes\Controller;

use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Services\BookPdfGeneratorService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/memoires/books')]
class BookPdfController extends AbstractController
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly BookPdfGeneratorService $pdfService
    ) {}

    #[Route('/{id}/pdf/preview-interior', name: 'api_memoires_book_preview_interior', methods: ['GET'])]
    public function previewInterior(string $id): Response
    {
        $em = $this->emProvider->getEntityManager();
        $book = $em->getRepository(Book::class)->find(Uuid::fromString($id));

        if (!$book) {
            return $this->json(['error' => 'Livre introuvable'], 404);
        }

        $pdfBinary = $this->pdfService->generateInteriorBinary($book);

        return new Response($pdfBinary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="interieur_' . $book->getTitle() . '.pdf"',
        ]);
    }

    #[Route('/{id}/pdf/preview-cover', name: 'api_memoires_book_preview_cover', methods: ['GET'])]
    public function previewCover(string $id): Response
    {
        $em = $this->emProvider->getEntityManager();
        $book = $em->getRepository(Book::class)->find(Uuid::fromString($id));

        if (!$book) {
            return $this->json(['error' => 'Livre introuvable'], 404);
        }

        $pdfBinary = $this->pdfService->generateCoverBinary($book);

        return new Response($pdfBinary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="couverture_' . $book->getTitle() . '.pdf"',
        ]);
    }

    #[Route('/{id}/pdf/generate', name: 'api_memoires_book_generate_pdfs', methods: ['POST'])]
    public function generatePdfs(string $id): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $book = $em->getRepository(Book::class)->find(Uuid::fromString($id));

        if (!$book) {
            return $this->json(['error' => 'Livre introuvable'], 404);
        }

        $result = $this->pdfService->generateAndSaveBookPdfs($book);

        return $this->json([
            'success' => true,
            'book_id' => $book->getId()->toRfc4122(),
            'interior_url' => '/' . $result['interior_path'],
            'cover_url' => '/' . $result['cover_path'],
            'page_count' => $result['page_count'],
        ]);
    }
}
