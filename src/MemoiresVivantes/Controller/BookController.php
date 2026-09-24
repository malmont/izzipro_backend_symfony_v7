<?php

namespace App\MemoiresVivantes\Controller;

use App\MemoiresVivantes\Dto\BookInputDto;
use App\MemoiresVivantes\Dto\BookOutputDto;
use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\BookPrintOrder;
use App\MemoiresVivantes\Entity\Contributor;
use App\MemoiresVivantes\Entity\MemoireQuestion;
use App\MemoiresVivantes\Services\BookService;
use App\MemoiresVivantes\UseCase\CreateBookUseCase;
use App\MemoiresVivantes\UseCase\GetBooksByUserUseCase;
use App\MemoiresVivantes\UseCase\UpdateBookUseCase;
use App\MemoiresVivantes\UseCase\DeleteBookUseCase;
use App\MemoiresVivantes\UseCase\Payment\SyncBookPaymentStatusUseCase;
use App\Services\MediaUrlResolver;
use App\MemoiresVivantes\UseCase\Reservation\GetBookReservationsUseCase;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        private readonly GetBookReservationsUseCase $getBookReservationsUseCase,
        private readonly SyncBookPaymentStatusUseCase $syncBookPaymentStatusUseCase,
        private readonly ?MediaUrlResolver $mediaUrlResolver = null
    ) {}

    private function resolveHost(Request $request): string
    {
        return $this->mediaUrlResolver?->getPublicHost($request->getSchemeAndHttpHost())
            ?? $request->getSchemeAndHttpHost();
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $em = $this->emProvider->getEntityManager();
        /** @var \App\MemoiresVivantes\Repository\BookRepository $bookRepo */
        $bookRepo = $em->getRepository(Book::class);

        $isAdmin = in_array('ROLE_ADMIN', $user->getRoles(), true) || in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
        $scope = $request->query->get('scope', $isAdmin ? 'all' : 'my');
        $targetUserId = $request->query->get('userId') ? (int) $request->query->get('userId') : null;

        if ($isAdmin && $scope === 'all') {
            $books = $bookRepo->findAllWithChaptersAndPhotos($targetUserId);
        } else {
            $books = $this->getBooksByUserUseCase->execute($user);
        }

        $host = $this->resolveHost($request);

        $latestOrdersByBookId = [];
        $ordersCountByBookId = [];

        if (!empty($books)) {
            // Précharger toutes les commandes pour ces livres en 1 seule requête
            /** @var BookPrintOrder[] $orders */
            $orders = $em->getRepository(BookPrintOrder::class)->createQueryBuilder('o')
                ->where('o.book IN (:books)')
                ->setParameter('books', $books)
                ->orderBy('o.createdAt', 'DESC')
                ->getQuery()
                ->getResult();

            foreach ($orders as $order) {
                $bookId = (string) $order->getBook()->getId();
                if (!isset($latestOrdersByBookId[$bookId])) {
                    $latestOrdersByBookId[$bookId] = $order;
                }
                $ordersCountByBookId[$bookId] = ($ordersCountByBookId[$bookId] ?? 0) + 1;
            }
        }

        $dtos = array_map(function($b) use ($host, $latestOrdersByBookId, $ordersCountByBookId) {
            $bId = (string) $b->getId();
            $latest = $latestOrdersByBookId[$bId] ?? null;
            $count = $ordersCountByBookId[$bId] ?? 0;
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

        // Si le statut de paiement est en attente, tentative de synchronisation en direct avec Stripe
        if ($book->getPaymentStatus() === 'pending') {
            $this->syncBookPaymentStatusUseCase->execute($book);
        }

        $res = $this->validateSignatureOrGrant('BOOK_VIEW', $book, $request);
        if ($res !== null) return $res;

        $validatedContributorId = $request->attributes->get('validatedContributorId');
        $contributorIdParam = $request->query->get('contributorId') ?? $request->query->get('contributor_id');
        $targetContribId = $validatedContributorId ?: $contributorIdParam;
        $currentContributor = null;
        $requestedRole = $request->query->get('role');
        $contributorRole = null;

        if ($targetContribId) {
            try {
                $contribRepo = $em->getRepository(Contributor::class);
                $contrib = $contribRepo->find(Uuid::fromString($targetContribId));
                if ($contrib) {
                    $contributorRole = $contrib->getRole();
                    $currentContributor = [
                        'id' => (string) $contrib->getId(),
                        'firstName' => $contrib->getFirstName(),
                        'role' => $contrib->getRole(),
                        'isApproved' => $contrib->isApproved(),
                        'approvedAt' => $contrib->getApprovedAt()?->format(\DateTimeInterface::ATOM),
                    ];
                }
            } catch (\Throwable) {}
        }

        $defaultRole = null;
        if ($book->getType() === 'famille') {
            $defaultRole = ($book->isParentsDeceased() || $book->isParentsNotParticipating()) ? 'enfant' : 'parent';
        }

        $filterRole = $requestedRole ?? $contributorRole ?? $defaultRole;

        $latestOrder = $em->getRepository(BookPrintOrder::class)->findOneBy(
            ['book' => $book],
            ['createdAt' => 'DESC']
        );
        $ordersCount = $latestOrder ? $em->getRepository(BookPrintOrder::class)->count(['book' => $book]) : 0;

        $questionsByTheme = [];
        if ($book->getType()) {
            $qb = $em->getRepository(MemoireQuestion::class)->createQueryBuilder('q')
                ->where('q.isActive = true')
                ->andWhere('q.bookType = :bookType')
                ->setParameter('bookType', $book->getType());

            if ($filterRole !== null) {
                $qb->andWhere('(q.role IS NULL OR q.role = :role)')
                   ->setParameter('role', $filterRole);
            }

            $qb->orderBy('q.displayOrder', 'ASC');
            foreach ($qb->getQuery()->getResult() as $q) {
                $questionsByTheme[$q->getTheme()][] = $q->toFrontArray();
            }
        }

        $host = $this->resolveHost($request);
        return $this->json(new BookOutputDto(
            $book,
            $host,
            $latestOrder,
            $ordersCount,
            $questionsByTheme,
            $targetContribId,
            $currentContributor
        ));
    }

    #[Route('/{id}/reservations', methods: ['GET'])]
    public function getReservations(string $id, Request $request): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $book = $em->getRepository(Book::class)->find(Uuid::fromString($id));
        if (!$book) return $this->json(['error' => 'Book not found'], 404);

        $res = $this->validateSignatureOrGrant('BOOK_VIEW', $book, $request);
        if ($res !== null) return $res;

        $reservations = $this->getBookReservationsUseCase->execute($id);
        return $this->json(array_map(fn($r) => new ReservationOutputDto($r), $reservations));
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $data = json_decode($request->getContent(), true) ?? [];
        $book = $this->createBookUseCase->execute($user, new BookInputDto($data));
        
        $host = $this->resolveHost($request);
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
        
        $host = $this->resolveHost($request);
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
        
        $host = $this->resolveHost($request);
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
        
        $host = $this->resolveHost($request);
        return $this->json(new BookOutputDto($book, $host));
    }

    private function validateSignatureOrGrant(string $attribute, Book $book, Request $request): ?JsonResponse
    {
        $expires = $request->query->get('expires');
        $signature = $request->query->get('signature');
        $chapterId = $request->query->get('chapterId') ?? $request->query->get('chapter_id');
        $contributorId = $request->query->get('contributorId') ?? $request->query->get('contributor_id');

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
            if ($contributorId === null) {
                if ($request->request->has('contributorId')) {
                    $contributorId = $request->request->get('contributorId');
                } elseif ($request->request->has('contributor_id')) {
                    $contributorId = $request->request->get('contributor_id');
                }
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
                    $contributorId = $contributorId ?? $data['contributorId'] ?? $data['contributor_id'] ?? null;
                }
            }
        }

        if ($expires === null || $signature === null || $chapterId === null) {
            $expires = $request->headers->get('X-Expires');
            $signature = $request->headers->get('X-Signature');
            $chapterId = $chapterId ?? $request->headers->get('X-Chapter-Id');
            $contributorId = $contributorId ?? $request->headers->get('X-Contributor-Id');
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
                    $contributorId = $contributorId ?? $params['contributorId'] ?? $params['contributor_id'] ?? null;
                }
            }
        }

        $hasValidSignature = false;
        if ($expires !== null && $signature !== null && $chapterId !== null) {
            if (time() <= (int)$expires) {
                $secret = $this->getParameter('kernel.secret');

                // 1. Signature spécifique au contributeur
                if ($contributorId !== null) {
                    $dataToSignWithContrib = "chapterId=" . $chapterId . "&contributorId=" . $contributorId . "&expires=" . $expires;
                    $expectedWithContrib = hash_hmac('sha256', $dataToSignWithContrib, $secret);
                    if (hash_equals($expectedWithContrib, $signature)) {
                        $em = $this->emProvider->getEntityManager();
                        $chapter = $em->getRepository(\App\MemoiresVivantes\Entity\Chapter::class)->find(Uuid::fromString($chapterId));
                        if ($chapter && (string)$chapter->getBook()->getId() === (string)$book->getId()) {
                            $hasValidSignature = true;
                            $request->attributes->set('validatedContributorId', $contributorId);
                        }
                    }
                }

                // 2. Signature classique globale
                if (!$hasValidSignature) {
                    $dataToSign = "chapterId=" . $chapterId . "&expires=" . $expires;
                    $expectedSignature = hash_hmac('sha256', $dataToSign, $secret);

                    if (hash_equals($expectedSignature, $signature)) {
                        $em = $this->emProvider->getEntityManager();
                        $chapter = $em->getRepository(\App\MemoiresVivantes\Entity\Chapter::class)->find(Uuid::fromString($chapterId));
                        if ($chapter && (string)$chapter->getBook()->getId() === (string)$book->getId()) {
                            $hasValidSignature = true;
                            if ($contributorId !== null) {
                                $request->attributes->set('validatedContributorId', $contributorId);
                            }
                        }
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
