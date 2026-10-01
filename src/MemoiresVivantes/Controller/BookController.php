<?php

namespace App\MemoiresVivantes\Controller;

use App\MemoiresVivantes\Dto\BookInputDto;
use App\MemoiresVivantes\Dto\BookOutputDto;
use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\BookPrintOrder;
use App\MemoiresVivantes\Entity\Contributor;
use App\MemoiresVivantes\Security\BookAccessGuard;
use App\MemoiresVivantes\Services\BookService;
use App\MemoiresVivantes\Services\ChapterQuestionProvider;
use App\MemoiresVivantes\BookType\BookTypeResolver;
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
        private readonly ChapterQuestionProvider $questionProvider,
        private readonly BookTypeResolver $bookTypeResolver,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly GetBookReservationsUseCase $getBookReservationsUseCase,
        private readonly SyncBookPaymentStatusUseCase $syncBookPaymentStatusUseCase,
        private readonly BookAccessGuard $accessGuard,
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

        // Interlocuteurs des chapitres : une lecture par type de livre, pas par livre
        $speakersByType = [];
        $dtos = array_map(function($b) use ($host, $latestOrdersByBookId, $ordersCountByBookId, &$speakersByType) {
            $bId = (string) $b->getId();
            $latest = $latestOrdersByBookId[$bId] ?? null;
            $count = $ordersCountByBookId[$bId] ?? 0;
            $speakersByType[$b->getType()] ??= $this->bookTypeResolver->speakersByTheme($b);
            return new BookOutputDto($b, $host, $latest, $count, speakersByTheme: $speakersByType[$b->getType()]);
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

        // Si le statut de paiement est en attente, tentative de synchronisation en direct avec Stripe (après le
        // contrôle d'accès : un appel anonyme ne doit pas déclencher de requête vers Stripe)
        if ($book->getPaymentStatus() === 'pending') {
            $this->syncBookPaymentStatusUseCase->execute($book);
        }

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

        $defaultRole = $this->bookTypeResolver->defaultRole($book);

        $filterRole = $requestedRole ?? $contributorRole ?? $defaultRole;

        $latestOrder = $em->getRepository(BookPrintOrder::class)->findOneBy(
            ['book' => $book],
            ['createdAt' => 'DESC']
        );
        $ordersCount = $latestOrder ? $em->getRepository(BookPrintOrder::class)->count(['book' => $book]) : 0;

        $questionsByTheme = $this->questionProvider->forBook($book, $filterRole);

        $host = $this->resolveHost($request);
        return $this->json(new BookOutputDto(
            $book,
            $host,
            $latestOrder,
            $ordersCount,
            $questionsByTheme,
            $targetContribId,
            $currentContributor,
            // E-mail du propriétaire et lien de paiement : jamais pour un invité venu par un lien de partage
            $this->getUser() !== null && $this->isGranted('BOOK_VIEW', $book),
            $this->bookTypeResolver->speakersByTheme($book)
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

        $data = json_decode($request->getContent(), true);
        $dto = new BookInputDto(is_array($data) ? $data : []);
        if ($dto->title === null || trim($dto->title) === '') {
            return $this->json(['error' => 'Le titre du livre est obligatoire.'], 422);
        }
        $book = $this->createBookUseCase->execute($user, $dto);
        
        $host = $this->resolveHost($request);
        return $this->json(new BookOutputDto($book, $host, speakersByTheme: $this->bookTypeResolver->speakersByTheme($book)), 201);
    }

    #[Route('/{id}', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $book = $em->getRepository(Book::class)->find(Uuid::fromString($id));
        if (!$book) return $this->json(['error' => 'Book not found'], 404);

        $this->denyAccessUnlessGranted('BOOK_EDIT', $book);

        $data = json_decode($request->getContent(), true);
        $book = $this->updateBookUseCase->execute($book, new BookInputDto(is_array($data) ? $data : []));
        
        $host = $this->resolveHost($request);
        return $this->json(new BookOutputDto($book, $host, speakersByTheme: $this->bookTypeResolver->speakersByTheme($book)));
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

        try {
            $this->bookService->updateCover($book, $file);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
        
        $host = $this->resolveHost($request);
        return $this->json(new BookOutputDto($book, $host, speakersByTheme: $this->bookTypeResolver->speakersByTheme($book)));
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
        return $this->json(new BookOutputDto($book, $host, speakersByTheme: $this->bookTypeResolver->speakersByTheme($book)));
    }

    /** Lien de chapitre signé ou voter : voir BookAccessGuard::canView */
    private function validateSignatureOrGrant(string $attribute, Book $book, Request $request): ?JsonResponse
    {
        $denied = $this->accessGuard->canView($book, $request, $attribute);

        return $denied === null ? null : $this->json(['error' => $denied[1]], $denied[0]);
    }
}
