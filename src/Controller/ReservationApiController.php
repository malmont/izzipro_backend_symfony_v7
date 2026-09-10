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
        private ValidatorInterface $validator
    ) {
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

            $reservation = $this->createReservationUseCase->execute($dto, $host, $locale);

            return $this->json([
                'success' => true,
                'message' => 'Réservation enregistrée',
                'data' => (array) new ReservationOutputDto($reservation)
            ], Response::HTTP_CREATED);

        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de l\'enregistrement de la réservation.',
                'error' => $e->getMessage()
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
