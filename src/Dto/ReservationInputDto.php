<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class ReservationInputDto
{
    #[Assert\Length(max: 100)]
    public ?string $service_id = null;

    #[Assert\NotBlank(message: 'Le nom du service est obligatoire.')]
    #[Assert\Length(max: 255)]
    public ?string $service_name = null;

    #[Assert\NotBlank(message: 'La date de réservation est obligatoire.')]
    #[Assert\Regex(
        pattern: '/^\d{4}-\d{2}-\d{2}$/',
        message: 'La date de réservation doit être au format AAAA-MM-JJ (YYYY-MM-DD).'
    )]
    public ?string $reservation_date = null;

    #[Assert\Length(max: 100)]
    public ?string $reservation_slot = null;

    #[Assert\NotBlank(message: 'Le nom du client est obligatoire.')]
    #[Assert\Length(max: 255)]
    public ?string $client_name = null;

    #[Assert\NotBlank(message: 'L\'adresse email est obligatoire.')]
    #[Assert\Email(message: 'L\'adresse email n\'est pas valide.')]
    #[Assert\Length(max: 255)]
    public ?string $client_email = null;

    #[Assert\NotBlank(message: 'Le numéro de téléphone est obligatoire.')]
    #[Assert\Length(max: 50)]
    public ?string $client_phone = null;

    #[Assert\Positive(message: 'Le nombre de personnes doit être supérieur à zéro.')]
    public int $number_of_guests = 1;

    public ?string $notes = null;

    public ?string $tenant_id = null;
}
