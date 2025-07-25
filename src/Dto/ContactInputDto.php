<?php
namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class ContactInputDto
{
    #[Assert\NotBlank(message: "Le nom ne peut pas être vide.")]
    #[Assert\Length(min: 2, max: 255)]
    public ?string $name = null;

    #[Assert\NotBlank]
    #[Assert\Email]
    public ?string $email = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: 5, max: 255)]
    public ?string $phone = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 255)]
    public ?string $subject = null;

    #[Assert\NotBlank]
    public ?string $Content = null;

    public ?bool $isRead = false;
}