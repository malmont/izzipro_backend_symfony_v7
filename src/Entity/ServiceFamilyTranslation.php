<?php

namespace App\Entity;

use App\Repository\ServiceFamilyTranslationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ServiceFamilyTranslationRepository::class)]
#[ORM\Table(name: 'service_family_translation')]
class ServiceFamilyTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private string $language = 'fr';

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\ManyToOne(inversedBy: 'translations')]
    #[ORM\JoinColumn(name: 'service_family_id', nullable: false, onDelete: 'CASCADE')]
    private ?ServiceFamily $family = null;

    public function getId(): ?int { return $this->id; }
    public function getLanguage(): string { return $this->language; }
    public function setLanguage(string $language): static { $this->language = $language; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(?string $name): static { $this->name = (string) $name; return $this; }
    public function getFamily(): ?ServiceFamily { return $this->family; }
    public function setFamily(?ServiceFamily $family): static { $this->family = $family; return $this; }
}
