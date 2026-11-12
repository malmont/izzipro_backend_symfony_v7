<?php

namespace App\Controller\BookingController;

use App\Dto\AvailabilityCheckDto;
use App\Services\Booking\BookingClock;
use App\UseCase\Booking\CheckAvailabilityUseCase;
use App\UseCase\Booking\GetCalendarAvailabilityUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/booking')]
class BookingController extends AbstractController
{
    #[Route('/check/{productId}', name: 'api_booking_check', methods: ['GET'])]
    public function check(
        int $productId,
        Request $request,
        ValidatorInterface $validator,
        CheckAvailabilityUseCase $useCase,
        BookingClock $clock,
        \App\Services\Http\ClientSafeError $safeError
    ): JsonResponse {
        $dto = new AvailabilityCheckDto();
        
        $dto->productId = $productId; 
        
        $dto->quantity = (int) $request->query->get('quantity', 1);

        try {
            // Fuseau de la boutique (04/11/2026) : sans fuseau = heure de la boutique, avec fuseau = converti
            $dto->startAt = BookingClock::utc($clock->parse((string) $request->query->get('start'))); // instant en UTC pour la requête de disponibilité
            $dto->endAt = BookingClock::utc($clock->parse((string) $request->query->get('end')));
        } catch (\Exception $e) {
            return $this->json(['error' => 'Format de date invalide (attendu: YYYY-MM-DD HH:MM)'], 400);
        }

        $errors = $validator->validate($dto);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return $this->json(['errors' => $errorMessages], 400);
        }

        try {
            $result = $useCase->execute($dto);

            // Indisponible : 409 avec remaining_stock (boutique réglable, 08/10/2026 ; 200 auparavant)
            return $this->json($result, $result['available'] ? 200 : 409);
            
        } catch (\Exception $e) {
            // lot ERREURS-1 (12/11/2026) : refus métier = message et statut d'aujourd'hui ; panne imprévue = texte neutre et 500 (avant :
            // le code de l'exception servait de statut, quel qu'il soit, et son message partait au client)
            if (!$safeError->isIntended($e)) {
                return $this->json($safeError->body($e, 'GET /api/booking/check/{productId}'), 500);
            }
            $code = is_int($e->getCode()) && $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
            if ($e->getMessage() === 'Stock insuffisant' || str_contains($e->getMessage(), 'Stock')) {
                $code = 409;
            }
            return $this->json(['error' => $e->getMessage()], $code);
        }
    }

    #[Route('/calendar/{productId}', name: 'api_booking_calendar', methods: ['GET'])]
    public function calendar(
        int $productId,
        Request $request,
        GetCalendarAvailabilityUseCase $useCase,
        BookingClock $clock,
        \App\Services\Http\ClientSafeError $safeError
    ): JsonResponse {
        $startStr = $request->query->get('start');
        $endStr = $request->query->get('end');

        if (!$startStr || !$endStr) {
            return $this->json(['error' => 'Paramètres start et end requis'], 400);
        }

        try {
            $timezone = $clock->timezone(); // fuseau de la boutique (04/11/2026 ; America/Toronto en dur auparavant) ; créneaux rendus en heures de la boutique, sans décalage
            try {
                $start = (new \DateTimeImmutable((string) $startStr, $timezone))->setTime(0, 0, 0);
                $end = (new \DateTimeImmutable((string) $endStr, $timezone))->setTime(23, 59, 59);
            } catch (\Exception) {
                // lot ERREURS-1 : avant, le message de PHP (« Failed to parse time string… ») partait au client
                return $this->json(['error' => 'Format de date invalide (attendu : YYYY-MM-DD)'], 400);
            }

            $data = $useCase->execute($productId, $start, $end);
            return $this->json($data);

        } catch (\Exception $e) {
            return $this->json($safeError->body($e, 'GET /api/booking/calendar/{productId}'), 400);
        }
    }
}