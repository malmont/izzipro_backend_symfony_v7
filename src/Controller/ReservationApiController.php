<?php

namespace App\Controller;

use App\Dto\ReservationInputDto;
use App\Dto\ReservationOutputDto;
use App\Services\TenantCacheService;
use App\UseCase\ReservationUseCase\CreateReservationUseCase;
use App\UseCase\ReservationUseCase\GetReservationServicesUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/reservations')]
class ReservationApiController extends AbstractController
{
    public function __construct(
        private CreateReservationUseCase $createReservationUseCase,
        private GetReservationServicesUseCase $getServicesUseCase,
        private TenantCacheService $cacheService,
        private ValidatorInterface $validator,
        private \App\Services\ReservationService\ReservationService $reservationService,
        private \App\Services\ReservationService\ReservationMailerService $mailerService,
        private \App\Services\TenantEntityManagerProvider $emProvider
    ) {
    }

    #[Route('', name: 'api_reservations_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié.'], Response::HTTP_UNAUTHORIZED);
        }

        $roles = method_exists($user, 'getRoles') ? $user->getRoles() : [];
        $isAdmin = in_array('ROLE_ADMIN', $roles, true) || in_array('ROLE_SUPER_ADMIN', $roles, true);

        $filters = [];

        // Cloisonnement RBAC :
        // - ROLE_USER (Biographe standard) : Ne voit STRICTEMENT que ses propres réservations
        // - ROLE_ADMIN : Voit tout par défaut (scope=all), ou ses propres entretiens (scope=my), ou filtre par collaborateur
        if (!$isAdmin) {
            $filters['biographerId'] = $user->getId();
        } else {
            $scope = $request->query->get('scope', 'all');
            if ($scope === 'my') {
                $filters['biographerId'] = $user->getId();
            } elseif ($request->query->get('biographer_id')) {
                $filters['biographerId'] = (int) $request->query->get('biographer_id');
            }
        }

        $start = $request->query->get('start') ?: $request->query->get('from');
        if ($start) {
            $filters['startDate'] = $start;
        }

        $end = $request->query->get('end') ?: $request->query->get('to');
        if ($end) {
            $filters['endDate'] = $end;
        }

        $status = $request->query->get('status');
        if ($status) {
            $filters['status'] = $status;
        }

        $bookId = $request->query->get('book_id');
        if ($bookId) {
            $filters['bookId'] = $bookId;
        }

        $reservations = $this->reservationService->getReservations($filters);

        return $this->json(array_map(fn($r) => new ReservationOutputDto($r), $reservations));
    }

    #[Route('', name: 'api_reservations_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (!is_array($data)) {
                return $this->json([
                    'success' => false,
                    'message' => 'Format JSON invalide.'
                ], Response::HTTP_BAD_REQUEST);
            }

            $dto = new ReservationInputDto();
            $dto->service_id = isset($data['service_id']) ? trim((string)$data['service_id']) : null;
            $dto->service_name = isset($data['service_name']) ? trim((string)$data['service_name']) : null;
            $dto->reservation_date = isset($data['reservation_date']) ? trim((string)$data['reservation_date']) : null;
            $dto->reservation_slot = isset($data['reservation_slot']) ? trim((string)$data['reservation_slot']) : null;
            $dto->client_name = isset($data['client_name']) ? trim((string)$data['client_name']) : null;
            $dto->client_email = isset($data['client_email']) ? trim((string)$data['client_email']) : null;
            $dto->client_phone = isset($data['client_phone']) ? trim((string)$data['client_phone']) : null;
            $dto->number_of_guests = isset($data['number_of_guests']) ? (int)$data['number_of_guests'] : 1;
            $dto->notes = isset($data['notes']) ? trim((string)$data['notes']) : null;
            $dto->tenant_id = isset($data['tenant_id']) ? trim((string)$data['tenant_id']) : null;
            $dto->book_id = isset($data['book_id']) ? trim((string)$data['book_id']) : null;
            $dto->chapter_id = isset($data['chapter_id']) ? trim((string)$data['chapter_id']) : null;
            $dto->step_number = isset($data['step_number']) ? (int)$data['step_number'] : null;
            $dto->total_steps = isset($data['total_steps']) ? (int)$data['total_steps'] : null;
            $dto->forfait_name = isset($data['forfait_name']) ? trim((string)$data['forfait_name']) : null;
            $dto->biographer_id = isset($data['biographer_id']) ? (int)$data['biographer_id'] : null;

            $violations = $this->validator->validate($dto);
            if (count($violations) > 0) {
                $errors = [];
                foreach ($violations as $violation) {
                    $errors[] = $violation->getMessage();
                }
                return $this->json([
                    'success' => false,
                    'message' => $violations[0]->getMessage(),
                    'errors' => $errors
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $host = $request->headers->get('X-Tenant-Host') ?: $request->getHost();
            $locale = $request->getLocale() ?: 'fr';

            $user = $this->getUser();
            $currentUser = ($user instanceof \App\Entity\User) ? $user : null;

            $reservation = $this->createReservationUseCase->execute($dto, $host, $locale, $currentUser);

            return $this->json([
                'success' => true,
                'message' => 'Réservation enregistrée',
                'data' => (array) new ReservationOutputDto($reservation)
            ], Response::HTTP_CREATED);

        } catch (\DomainException $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ], Response::HTTP_CONFLICT);
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de l\'enregistrement de la réservation.',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/booked-slots', name: 'api_reservations_booked_slots', methods: ['GET'])]
    public function getBookedSlots(Request $request): JsonResponse
    {
        $date = $request->query->get('date');
        $month = $request->query->get('month');

        // Résolution du biographe :
        // 1. Paramètre explicite ?biographer_id=X
        // 2. Déduction depuis ?book_id=UUID
        // 3. Si utilisateur connecté et non-admin, défaut sur son propre ID
        $biographerId = null;
        if ($request->query->get('biographer_id')) {
            $biographerId = (int) $request->query->get('biographer_id');
        } elseif ($request->query->get('book_id')) {
            try {
                $tenantEm = $this->emProvider->getEntityManager();
                $book = $tenantEm->getRepository(\App\MemoiresVivantes\Entity\Book::class)->find(
                    \Symfony\Component\Uid\Uuid::fromString($request->query->get('book_id'))
                );
                if ($book && $book->getUser()) {
                    $biographerId = $book->getUser()->getId();
                }
            } catch (\Throwable $e) {
                // Ignore format error and proceed
            }
        } else {
            $user = $this->getUser();
            if ($user instanceof \App\Entity\User) {
                $roles = $user->getRoles();
                if (!in_array('ROLE_ADMIN', $roles, true) && !in_array('ROLE_SUPER_ADMIN', $roles, true)) {
                    $biographerId = $user->getId();
                }
            }
        }

        if ($date) {
            try {
                $dateTime = new \DateTime($date);
                $slots = $this->reservationService->getBookedSlotsByDate($dateTime, $biographerId);
                return $this->json([
                    'success' => true,
                    'date' => $dateTime->format('Y-m-d'),
                    'biographer_id' => $biographerId,
                    'booked_slots' => $slots
                ]);
            } catch (\Throwable $e) {
                return $this->json([
                    'success' => false,
                    'message' => 'Format de date invalide. Utilisez YYYY-MM-DD.'
                ], Response::HTTP_BAD_REQUEST);
            }
        }

        if ($month) {
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                return $this->json([
                    'success' => false,
                    'message' => 'Format de mois invalide. Utilisez YYYY-MM.'
                ], Response::HTTP_BAD_REQUEST);
            }

            $bookedSlots = $this->reservationService->getBookedSlotsByMonth($month, $biographerId);
            return $this->json([
                'success' => true,
                'month' => $month,
                'biographer_id' => $biographerId,
                'booked_slots_by_date' => $bookedSlots
            ]);
        }

        return $this->json([
            'success' => false,
            'message' => 'Veuillez fournir le paramètre ?date=YYYY-MM-DD ou ?month=YYYY-MM'
        ], Response::HTTP_BAD_REQUEST);
    }

    #[Route('/{id}/confirm', name: 'api_reservations_confirm', methods: ['POST'])]
    public function confirm(int $id, Request $request): JsonResponse
    {
        try {
            $reservation = $this->reservationService->confirmReservation($id);

            if (!$reservation) {
                return $this->json([
                    'success' => false,
                    'message' => 'Réservation introuvable pour l\'identifiant fourni.'
                ], Response::HTTP_NOT_FOUND);
            }

            // Déclencher l'envoi de notification email de confirmation (non-bloquant)
            try {
                $host = $request->headers->get('X-Tenant-Host') ?: $request->getHost();
                $locale = $request->getLocale() ?: 'fr';
                $this->mailerService->sendStatusChangeEmail($reservation, 'confirmed', $locale, $host);
            } catch (\Throwable $e) {
                // Log l'erreur d'email sans impacter le succès de la confirmation
            }

            return $this->json([
                'success' => true,
                'message' => 'La réservation a été confirmée avec succès. En cas d\'empêchement, le client peut répondre à l\'email de confirmation ou nous appeler directement.',
                'reservation' => [
                    'id' => $reservation->getId(),
                    'status' => $reservation->getStatus(),
                    'reservation_date' => $reservation->getReservationDate()?->format('Y-m-d'),
                    'reservation_slot' => $reservation->getReservationSlot(),
                    'client_name' => $reservation->getClientName(),
                    'client_email' => $reservation->getClientEmail(),
                ]
            ], Response::HTTP_OK);

        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la confirmation de la réservation.'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/services', name: 'api_reservations_services', methods: ['GET'])]
    public function getServices(): JsonResponse
    {
        $services = $this->cacheService->get('reservations_services_list', function () {
            return $this->getServicesUseCase->execute();
        }, 3600, ['reservations']);

        return $this->json($services, Response::HTTP_OK);
    }
}
