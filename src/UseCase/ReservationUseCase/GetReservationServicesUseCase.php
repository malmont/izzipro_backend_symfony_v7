<?php

namespace App\UseCase\ReservationUseCase;

use App\Services\ReservationService\ReservationService;

class GetReservationServicesUseCase
{
    public function __construct(
        private ReservationService $reservationService
    ) {
    }

    public function execute(): array
    {
        return $this->reservationService->getReservableServices();
    }
}
