<?php

namespace App\UseCase\ReservationUseCase;

use App\Dto\ReservationInputDto;
use App\Entity\Reservation;
use App\Services\ReservationService\ReservationMailerService;
use App\Services\ReservationService\ReservationService;

class CreateReservationUseCase
{
    public function __construct(
        private ReservationService $reservationService,
        private ReservationMailerService $mailerService
    ) {
    }

    public function execute(ReservationInputDto $dto, string $host, string $locale = 'fr'): Reservation
    {
        $reservation = $this->reservationService->createReservation($dto, $host);

        // Envoi des emails avec intégration Google Calendar & pièce jointe .ics
        $this->mailerService->sendReservationEmails($reservation, $locale, $host);

        return $reservation;
    }
}
