<?php

namespace App\MemoiresVivantes\Controller;

use App\MemoiresVivantes\Dto\BookInputDto;
use App\MemoiresVivantes\Dto\BookOutputDto;
use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\BookPrintOrder;
use App\MemoiresVivantes\Services\BookService;
use App\MemoiresVivantes\UseCase\CreateBookUseCase;
use App\MemoiresVivantes\UseCase\GetBooksByUserUseCase;
use App\MemoiresVivantes\UseCase\UpdateBookUseCase;
use App\MemoiresVivantes\UseCase\DeleteBookUseCase;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Services\ReservationService\ReservationService;
use App\Dto\ReservationOutputDto;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/memoires/books')]
class BookController extends AbstractController
{
    public function __construct(
        private readonly CreateBookUseCase $createBookUseCase,
        private readonly GetBooksByUserUseCase $getBooksByUserUseCase,
        private readonly UpdateBookUseCase $updateBookUseCase,
        private readonly DeleteBookUseCase $deleteBookUseCase,
        private readonly BookService $bookService,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly ReservationService $reservationService
    ) {}

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $books = $this->getBooksByUserUseCase->execute($user);
        $host = $request->getSchemeAndHttpHost();
        $em = $this->emProvider->getEntityManager();
        $orderRepo = $em->getRepository(BookPrintOrder::class);

        $dtos = array_map(function($b) use ($host, $orderRepo) {
            $latest = $orderRepo->findOneBy(['book' => $b], ['createdAt' => 'DESC']);
            $count = $latest ? $orderRepo->count(['book' => $b]) : 0;
            return new BookOutputDto($b, $host, $latest, $count);
        }, $books);

        return $this->json($dtos);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function get(string $id, Request $request): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $book = $em->getRepository(Book::class)->find(Uuid::fromString($id));
        if (!$book) return $this->json(['error' => 'Book not found'], 404);

        $res = $this->validateSignatureOrGrant('BOOK_VIEW', $book, $request);
        if ($res !== null) return $res;

        $latestOrder = $em->getRepository(BookPrintOrder::class)->findOneBy(
            ['book' => $book],
            ['createdAt' => 'DESC']
        );
        $ordersCount = $latestOrder ? $em->getRepository(BookPrintOrder::class)->count(['book' => $book]) : 0;

        $host = $request->getSchemeAndHttpHost();
        return $this->json(new BookOutputDto($book, $host, $latestOrder, $ordersCount));
    }

    #[Route('/{id}/reservations', methods: ['GET'])]
    public function getReservations(string $id, Request $request): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $book = $em->getRepository(Book::class)->find(Uuid::fromString($id));
        if (!$book) return $this->json(['error' => 'Book not found'], 404);

        $res = $this->validateSignatureOrGrant('BOOK_VIEW', $book, $request);
        if ($res !== null) return $res;

        $reservations = $this->reservationService->getReservationsByBook($id);
        return $this->json(array_map(fn($r) => new ReservationOutputDto($r), $reservations));
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $data = json_decode($request->getContent(), true) ?? [];
        $book = $this->createBookUseCase->execute($user, new BookInputDto($data));
        
        $host = $request->getSchemeAndHttpHost();
        return $this->json(new BookOutputDto($book, $host), 201);
    }

    #[Route('/{id}', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $book = $em->getRepository(Book::class)->find(Uuid::fromString($id));
        if (!$book) return $this->json(['error' => 'Book not found'], 404);

        $this->denyAccessUnlessGranted('BOOK_EDIT', $book);

        $data = json_decode($request->getContent(), true) ?? [];
        $book = $this->updateBookUseCase->execute($book, new BookInputDto($data));
        
        $host = $request->getSchemeAndHttpHost();
        return $this->json(new BookOutputDto($book, $host));
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $book = $em->getRepository(Book::class)->find(Uuid::fromString($id));
        if (!$book) return $this->json(['error' => 'Book not found'], 404);

        $this->denyAccessUnlessGranted('BOOK_DELETE', $book);

        $this->deleteBookUseCase->execute($book);
        return $this->json(['status' => 'Book deleted']);
    }

    #[Route('/{id}/cover', methods: ['POST'])]
    public function uploadCover(string $id, Request $request): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $book = $em->getRepository(Book::class)->find(Uuid::fromString($id));
        if (!$book) return $this->json(['error' => 'Book not found'], 404);

        $this->denyAccessUnlessGranted('BOOK_EDIT', $book);

        $file = $request->files->get('cover')
            ?? $request->files->get('file')
            ?? ($request->files->count() > 0 ? $request->files->getIterator()->current() : null);
        if (!$file) return $this->json(['error' => 'No file uploaded'], 400);

        $this->bookService->updateCover($book, $file);
        
        $host = $request->getSchemeAndHttpHost();
        return $this->json(new BookOutputDto($book, $host));
    }

    #[Route('/{id}/cover', methods: ['DELETE'])]
    public function removeCover(string $id, Request $request): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $book = $em->getRepository(Book::class)->find(Uuid::fromString($id));
        if (!$book) return $this->json(['error' => 'Book not found'], 404);

        $this->denyAccessUnlessGranted('BOOK_EDIT', $book);

        $this->bookService->removeCover($book);
        
        $host = $request->getSchemeAndHttpHost();
        return $this->json(new BookOutputDto($book, $host));
    }

    private function validateSignatureOrGrant(string $attribute, Book $book, Request $request): ?JsonResponse
    {
        $expires = $request->query->get('expires');
        $signature = $request->query->get('signature');
        $chapterId = $request->query->get('chapterId') ?? $request->query->get('chapter_id');

        if ($expires === null || $signature === null || $chapterId === null) {
            if ($request->request->has('expires')) {
                $expires = $request->request->get('expires');
            }
            if ($request->request->has('signature')) {
                $signature = $request->request->get('signature');
            }
            if ($request->request->has('chapterId')) {
                $chapterId = $request->request->get('chapterId');
            } elseif ($request->request->has('chapter_id')) {
                $chapterId = $request->request->get('chapter_id');
            }
        }

        if ($expires === null || $signature === null || $chapterId === null) {
            $content = $request->getContent();
            if ($content) {
                $data = json_decode($content, true);
                if (is_array($data)) {
                    $expires = $expires ?? $data['expires'] ?? null;
                    $signature = $signature ?? $data['signature'] ?? null;
                    $chapterId = $chapterId ?? $data['chapterId'] ?? $data['chapter_id'] ?? null;
                }
            }
        }

        if ($expires === null || $signature === null || $chapterId === null) {
            $expires = $request->headers->get('X-Expires');
            $signature = $request->headers->get('X-Signature');
            $chapterId = $chapterId ?? $request->headers->get('X-Chapter-Id');
        }

        if ($expires === null || $signature === null || $chapterId === null) {
            $referer = $request->headers->get('Referer');
            if ($referer) {
                $query = parse_url($referer, PHP_URL_QUERY);
                if ($query) {
                    parse_str($query, $params);
                    $expires = $expires ?? $params['expires'] ?? null;
                    $signature = $signature ?? $params['signature'] ?? null;
                    $chapterId = $chapterId ?? $params['chapterId'] ?? $params['chapter_id'] ?? null;
                }
            }
        }

        $hasValidSignature = false;
        if ($expires !== null && $signature !== null && $chapterId !== null) {
            if (time() <= (int)$expires) {
                $secret = $this->getParameter('kernel.secret');
                $dataToSign = "chapterId=" . $chapterId . "&expires=" . $expires;
                $expectedSignature = hash_hmac('sha256', $dataToSign, $secret);

                if (hash_equals($expectedSignature, $signature)) {
                    $em = $this->emProvider->getEntityManager();
                    $chapter = $em->getRepository(\App\MemoiresVivantes\Entity\Chapter::class)->find(Uuid::fromString($chapterId));
                    if ($chapter && (string)$chapter->getBook()->getId() === (string)$book->getId()) {
                        $hasValidSignature = true;
                    }
                }
            }
        }

        if ($hasValidSignature) {
            return null;
        }

        $user = $this->getUser();
        if ($user !== null) {
            try {
                $this->denyAccessUnlessGranted($attribute, $book);
                return null;
            } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException $e) {
                // proceed to return JsonResponse below
            }
        }

        if ($expires !== null && $signature !== null && $chapterId !== null) {
            if (time() > (int)$expires) {
                return $this->json(['error' => 'This sharing link has expired.'], \Symfony\Component\HttpFoundation\Response::HTTP_UNAUTHORIZED);
            }
            return $this->json(['error' => 'Invalid signature or resource mismatch.'], \Symfony\Component\HttpFoundation\Response::HTTP_UNAUTHORIZED);
        }

        return $this->json(['error' => 'Access denied. Missing or invalid signature.'], \Symfony\Component\HttpFoundation\Response::HTTP_UNAUTHORIZED);
    }
}
