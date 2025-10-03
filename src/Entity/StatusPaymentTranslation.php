<?php

namespace App\Entity;

use App\Repository\StatusPaymentTranslationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StatusPaymentTranslationRepository::class)]
class StatusPaymentTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private ?string $language = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT , nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(inversedBy: 'translations')]
    private ?StatusPayment $statusPayment = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(string $language): static
    {
        $this->language = $language;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getStatusPayment(): ?StatusPayment
    {
        return $this->statusPayment;
    }

    public function setStatusPayment(?StatusPayment $statusPayment): static
    {
        $this->statusPayment = $statusPayment;

        return $this;
    }
}
