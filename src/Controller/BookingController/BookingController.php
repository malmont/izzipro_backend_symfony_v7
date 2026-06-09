<?php

namespace App\Controller\BookingController;

use App\Dto\AvailabilityCheckDto;
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
        CheckAvailabilityUseCase $useCase
    ): JsonResponse {
        $dto = new AvailabilityCheckDto();
        
        $dto->productId = $productId; 
        
        $dto->quantity = (int) $request->query->get('quantity', 1);

        try {
            $dto->startAt = new \DateTimeImmutable($request->query->get('start'));
            $dto->endAt = new \DateTimeImmutable($request->query->get('end'));
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
            
            return $this->json($result);
            
        } catch (\Exception $e) {
            $code = $e->getCode() ?: 500;
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
        GetCalendarAvailabilityUseCase $useCase
    ): JsonResponse {
        $startStr = $request->query->get('start');
        $endStr = $request->query->get('end');

        if (!$startStr || !$endStr) {
            return $this->json(['error' => 'Paramètres start et end requis'], 400);
        }

        try {
            $timezone = new \DateTimeZone('America/Toronto');
            $start = (new \DateTimeImmutable($startStr, $timezone))->setTime(0, 0, 0);
            $end = (new \DateTimeImmutable($endStr, $timezone))->setTime(23, 59, 59);
            
            $data = $useCase->execute($productId, $start, $end);
            return $this->json($data);

        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
    }
}