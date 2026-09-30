<?php

namespace App\MemoiresVivantes\Controller;

use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Services\BookPdfGeneratorService;
use App\Services\TenantEntityManagerProvider;
use App\MemoiresVivantes\Security\BookAccessGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
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
        private readonly BookPdfGeneratorService $pdfService,
        private readonly BookAccessGuard $accessGuard
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
        if ($denied = $this->accessGuard->canView($book, $request)) {
            return $this->json(['error' => $denied[1]], $denied[0]);
        }

        $author = BookPdfGeneratorService::normalizeAuthorName($request->query->all()['author'] ?? null);
        $pdfBinary = $this->pdfService->generateInteriorBinary($book, $author);

        return new Response($pdfBinary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => self::inlineDisposition('interieur_' . $book->getTitle()),
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
        if ($denied = $this->accessGuard->canView($book, $request)) {
            return $this->json(['error' => $denied[1]], $denied[0]);
        }

        $style = (string)$request->query->get('style', 'biographic_split');
        $author = BookPdfGeneratorService::normalizeAuthorName($request->query->all()['author'] ?? null);
        $pages = max(24, (int)$request->query->get('pages', 64));
        $color = (string)($request->query->get('color') ?: $request->query->get('bg') ?: $request->query->get('bg_color') ?: '');
        if (!empty($color) && !str_starts_with($color, '#') && ctype_xdigit($color)) {
            $color = '#' . $color;
        }

        $pdfBinary = $this->pdfService->generateCoverBinary($book, $pages, $style, $author, $color ?: null);

        return new Response($pdfBinary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => self::inlineDisposition('couverture_' . $style . '_' . $book->getTitle()),
            // Géométrie de la couverture dépliée (points PDF) : le frontend découpe 4e, tranche et 1re sans approximation
            'X-Cover-Geometry' => json_encode($this->pdfService->coverGeometry($pages)),
            'Access-Control-Expose-Headers' => 'X-Cover-Geometry',
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
        if ($denied = $this->accessGuard->canManage($book)) {
            return $this->json(['error' => $denied[1]], $denied[0]);
        }

        $data = json_decode($request->getContent(), true) ?: [];
        $style = is_string($data['cover_style'] ?? null) ? $data['cover_style'] : (string) $request->query->get('style', 'biographic_split');
        // Texte, ou objet « author » du livre renvoyé tel quel par le frontend (provoquait une erreur 500)
        $author = BookPdfGeneratorService::normalizeAuthorName($data['author_name'] ?? $data['author'] ?? $request->query->all()['author'] ?? null);
        $color = $data['bg_color'] ?? $data['color'] ?? $request->query->get('color') ?? $request->query->get('bg_color') ?? '';
        $color = is_string($color) ? $color : '';
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
            'cover_geometry' => $result['cover_geometry'],
        ]);
    }

    /** Nom de fichier sûr pour l'en-tête (accents, guillemets et retours à la ligne d'un titre de livre) */
    private static function inlineDisposition(string $name): string
    {
        $name = trim((string) preg_replace('#[\\\\/\x00-\x1F%"]+#u', ' ', $name)) ?: 'livre';
        $ascii = trim((string) preg_replace('/[^A-Za-z0-9 ._-]+/', '_', (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name)), '_ ') ?: 'livre';

        return HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $name . '.pdf', $ascii . '.pdf');
    }
}
