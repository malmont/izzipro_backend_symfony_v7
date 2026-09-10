<?php

namespace App\Dto;

use App\Entity\Reservation;

class ReservationOutputDto
{
    public ?int $id;
    public ?string $service_id;
    public ?string $service_name;
    public ?string $reservation_date;
    public ?string $reservation_slot;
    public ?string $client_name;
    public ?string $client_email;
    public ?string $client_phone;
    public int $number_of_guests;
    public ?string $notes;
    public string $status;
    public ?string $created_at;

    public function __construct(Reservation $reservation)
    {
        $this->id = $reservation->getId();
        $this->service_id = $reservation->getServiceId();
        $this->service_name = $reservation->getServiceName();
        $this->reservation_date = $reservation->getReservationDate()?->format('Y-m-d');
        $this->reservation_slot = $reservation->getReservationSlot();
        $this->client_name = $reservation->getClientName();
        $this->client_email = $reservation->getClientEmail();
        $this->client_phone = $reservation->getClientPhone();
        $this->number_of_guests = $reservation->getNumberOfGuests();
        $this->notes = $reservation->getNotes();
        $this->status = $reservation->getStatus();
        $this->created_at = $reservation->getCreatedAt()?->format(\DateTimeInterface::ATOM);
    }
}
