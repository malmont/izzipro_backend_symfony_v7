<?php

namespace App\Entity;

use App\Repository\EmailConfigurationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EmailConfigurationRepository::class)]
class EmailConfiguration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $fromEmail = null;

    #[ORM\Column(length: 255)]
    private ?string $fromName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logo = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $signature = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFromEmail(): ?string
    {
        return $this->fromEmail;
    }

    public function setFromEmail(string $fromEmail): static
    {
        $this->fromEmail = $fromEmail;

        return $this;
    }

    public function getFromName(): ?string
    {
        return $this->fromName;
    }

    public function setFromName(string $fromName): static
    {
        $this->fromName = $fromName;

        return $this;
    }

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(?string $logo): static
    {
        $this->logo = $logo;

        return $this;
    }

    public function getSignature(): ?string
    {
        return $this->signature;
    }

    public function setSignature(?string $signature): static
    {
        $this->signature = $signature;

        return $this;
    }
}
