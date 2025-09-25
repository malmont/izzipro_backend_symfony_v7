<?php

namespace App\Entity;

use App\Repository\EmailConfigurationTranslationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EmailConfigurationTranslationRepository::class)]
class EmailConfigurationTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private ?string $language = null;

    #[ORM\Column(length: 255)]
    private ?string $fromName = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $signature = null;

    #[ORM\ManyToOne(inversedBy: 'translations')]
    private ?EmailConfiguration $emailConfiguration = null;

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

    public function getFromName(): ?string
    {
        return $this->fromName;
    }

    public function setFromName(string $fromName): static
    {
        $this->fromName = $fromName;

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

    public function getEmailConfiguration(): ?EmailConfiguration
    {
        return $this->emailConfiguration;
    }

    public function setEmailConfiguration(?EmailConfiguration $emailConfiguration): static
    {
        $this->emailConfiguration = $emailConfiguration;

        return $this;
    }
}
