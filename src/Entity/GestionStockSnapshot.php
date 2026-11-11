<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Instantané quotidien de la valeur du stock (lot S1, 11/11/2026, `docs/statistiques-gestion.md` § 4) : un par jour du magasin, pris par le
 * passage quotidien de 10 h (`app:gestion:notifications:envoyer`) ; valeur de vente par variante (règle de `stock_value_cents`, B1) et valeur
 * au coût connu. Écrit et lu en SQL par `GestionStockSnapshotService`, jamais en cache applicatif (`CacheTags::NO_CACHE_READ`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'gestion_stock_snapshot')]
class GestionStockSnapshot
{
    #[ORM\Id]
    #[ORM\Column(name: 'snapshot_date', type: 'date_immutable')]
    private ?\DateTimeImmutable $snapshotDate = null;

    #[ORM\Column(name: 'sale_value_cents', type: 'bigint')]
    private string|int $saleValueCents = 0;

    #[ORM\Column(name: 'cost_value_cents', type: 'bigint')]
    private string|int $costValueCents = 0;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    public function getSnapshotDate(): ?\DateTimeImmutable { return $this->snapshotDate; }
}
