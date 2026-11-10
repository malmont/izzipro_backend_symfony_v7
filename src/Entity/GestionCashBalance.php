<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Solde de trésorerie saisi par le gérant (lot B1b-1, 11/11/2026, `docs/tresorerie-gestion.md` § 1) : montant à la fin de sa date, en cents
 * signés dans la devise du site (un découvert est négatif), une ligne par date. Le backend ne connaît pas le solde bancaire.
 * Écrit et lu en SQL par `GestionCashService`, jamais en cache applicatif (`CacheTags::NO_CACHE_READ`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'gestion_cash_balance')]
#[ORM\UniqueConstraint(name: 'uniq_gestion_cash_balance_date', columns: ['balance_date'])]
class GestionCashBalance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'balance_date', type: 'date_immutable')]
    private ?\DateTimeImmutable $balanceDate = null;

    #[ORM\Column(name: 'amount_cents', type: 'bigint')]
    private string|int $amountCents = 0;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'created_by', length: 255, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int { return $this->id; }
}
