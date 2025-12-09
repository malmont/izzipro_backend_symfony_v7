<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class AvailabilityCheckDto
{
    #[Assert\NotBlank(message: "L'ID du produit est obligatoire.")]
    #[Assert\Type('integer')]
    public ?int $productId = null;

    #[Assert\NotBlank(message: "La date de début est obligatoire.")]
    #[Assert\Type("\DateTimeInterface")]
    public ?\DateTimeInterface $startAt = null;

    #[Assert\NotBlank(message: "La date de fin est obligatoire.")]
    #[Assert\Type("\DateTimeInterface")]
    #[Assert\GreaterThan(propertyPath: "startAt", message: "La date de fin doit être postérieure à la date de début.")]
    public ?\DateTimeInterface $endAt = null;

    #[Assert\Type('integer')]
    #[Assert\Positive(message: "La quantité doit être supérieure à 0.")]
    public int $quantity = 1;
}