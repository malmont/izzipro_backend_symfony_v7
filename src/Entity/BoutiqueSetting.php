<?php

namespace App\Entity;

use App\Repository\BoutiqueSettingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Réglages publiés de la boutique réglable (une ligne JSON par tenant), même forme que LandingPageSetting :
 * navbar, footer, onglets (dont les pages système), modèles personnels, charte, commerce.
 */
#[ORM\Entity(repositoryClass: BoutiqueSettingRepository::class)]
#[ORM\Table(name: 'boutique_setting')]
class BoutiqueSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private array $configuration = [];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConfiguration(): array
    {
        return $this->configuration;
    }

    public function setConfiguration(array $configuration): static
    {
        $this->configuration = $configuration;

        return $this;
    }
}
