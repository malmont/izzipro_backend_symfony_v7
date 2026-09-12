<?php

namespace App\MemoiresVivantes\Controller;

use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Services\BookPdfGeneratorService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
    #[Route('/{bookId}/pdf/preview-interior', methods: ['GET'])]
    public function previewInterior(?string $id = null, ?string $bookId = null, Request $request = null): Response
    {
        $targetId = $id ?: $bookId;
        $em = $this->emProvider->getEntityManager();
        $book = $em->getRepository(Book::class)->find(Uuid::fromString($targetId));

        if (!$book) {
            return $this->json(['error' => 'Livre introuvable'], 404);
        }

        $author = $request->query->get('author');
        $pdfBinary = $this->pdfService->generateInteriorBinary($book, $author);

        return new Response($pdfBinary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="interieur_' . $book->getTitle() . '.pdf"',
        ]);
    }

    #[Route('/{id}/pdf/preview-cover', name: 'api_memoires_book_preview_cover', methods: ['GET'])]
    #[Route('/{bookId}/pdf/preview-cover', methods: ['GET'])]
    public function previewCover(?string $id = null, ?string $bookId = null, Request $request = null): Response
    {
        $targetId = $id ?: $bookId;
        $em = $this->emProvider->getEntityManager();
        $book = $em->getRepository(Book::class)->find(Uuid::fromString($targetId));

        if (!$book) {
            return $this->json(['error' => 'Livre introuvable'], 404);
        }

        $style = (string)$request->query->get('style', 'biographic_split');
        $author = $request->query->get('author');
        $pages = max(24, (int)$request->query->get('pages', 64));
        $color = (string)($request->query->get('color') ?: $request->query->get('bg') ?: $request->query->get('bg_color') ?: '');
        if (!empty($color) && !str_starts_with($color, '#') && ctype_xdigit($color)) {
            $color = '#' . $color;
        }

        $pdfBinary = $this->pdfService->generateCoverBinary($book, $pages, $style, $author, $color ?: null);

        return new Response($pdfBinary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="couverture_' . $style . '_' . $book->getTitle() . '.pdf"',
        ]);
    }

    #[Route('/{id}/pdf/generate', name: 'api_memoires_book_generate_pdfs', methods: ['POST'])]
    #[Route('/{bookId}/pdf/generate', methods: ['POST'])]
    public function generatePdfs(?string $id = null, ?string $bookId = null, Request $request = null): JsonResponse
    {
        $targetId = $id ?: $bookId;
        $em = $this->emProvider->getEntityManager();
        $book = $em->getRepository(Book::class)->find(Uuid::fromString($targetId));

        if (!$book) {
            return $this->json(['error' => 'Livre introuvable'], 404);
        }

        $data = json_decode($request->getContent(), true) ?: [];
        $style = (string)($data['cover_style'] ?? $request->query->get('style', 'biographic_split'));
        $author = $data['author_name'] ?? $request->query->get('author');
        $color = (string)($data['bg_color'] ?? $data['color'] ?? $request->query->get('color') ?? $request->query->get('bg_color') ?? '');
        if (!empty($color) && !str_starts_with($color, '#') && ctype_xdigit($color)) {
            $color = '#' . $color;
        }

        $result = $this->pdfService->generateAndSaveBookPdfs($book, $style, $author, $color ?: null);

        return $this->json([
            'success' => true,
            'book_id' => $book->getId()->toRfc4122(),
            'interior_url' => '/' . $result['interior_path'],
            'cover_url' => '/' . $result['cover_path'],
            'page_count' => $result['page_count'],
        ]);
    }
}
