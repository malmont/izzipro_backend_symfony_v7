<?php

namespace App\LossPrevention\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Le mot de passe d'accès des puces du site (lot L3-c, § 16.20), rangé CHIFFRÉ : son rang (un mot de passe par rang ; un seul
 * rang aujourd'hui), le rang de la clé qui l'a chiffré, son chiffre. La clé vit hors de la base (`LossTagVault`). Il n'est lu que
 * pour être remis au boîtier avec un travail d'impression : jamais affiché, jamais imprimé, dans aucun journal ni aucun fait. La
 * base refuse de le supprimer : sans lui, les puces déjà écrites ne se réécrivent plus.
 */
#[ORM\Entity]
#[ORM\Table(name: 'loss_tag_secret')]
#[ORM\UniqueConstraint(name: 'uniq_loss_tag_secret_rank', columns: ['rank'])]
class LossTagSecret
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $rank;

    #[ORM\Column(name: 'key_rank')]
    private int $keyRank;

    #[ORM\Column(type: 'text')]
    private string $cipher;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function getId(): ?int { return $this->id; }
}
