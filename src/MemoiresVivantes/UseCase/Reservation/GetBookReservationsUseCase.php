<?php

namespace App\MemoiresVivantes\UseCase\Reservation;

use App\Services\ReservationService\ReservationService;

class GetBookReservationsUseCase
{
    public function __construct(
        private readonly ReservationService $reservationService
    ) {}

    /**
     * Récupère la liste ordonnée des réservations associées à un livre.
     *
     * @param string $bookId
     * @return array
     */
    public function execute(string $bookId): array
    {
        return $this->reservationService->getReservationsByBook($bookId);
    }
}
