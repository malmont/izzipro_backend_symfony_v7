<?php

namespace App\LossPrevention\Entity;

use App\LossPrevention\Repository\LossCounterAlertDayRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Compteurs de l'alerte en caisse (lot L5-a, `docs/prevention-pertes.md` § 18.2, « B ») : pour un poste et un jour du magasin, combien de
 * fois la caisse a montré des articles suivis non lus avant un paiement (`shown`), et combien de fois le caissier a continué quand même
 * (`proceeded`). Ni personne, ni vente, ni article. Lus et écrits en SQL par `LossCounterAlertDayRepository`.
 */
#[ORM\Entity(repositoryClass: LossCounterAlertDayRepository::class)]
#[ORM\Table(name: 'loss_counter_alert_day')]
#[ORM\Index(name: 'idx_loss_counter_alert_day_day', columns: ['store_day'])]
class LossCounterAlertDay
{
    #[ORM\Id]
    #[ORM\Column(name: 'cash_register_id')]
    private int $cashRegisterId;

    #[ORM\Id]
    #[ORM\Column(name: 'store_day', type: 'date_immutable')]
    private \DateTimeImmutable $storeDay;

    #[ORM\Column(options: ['default' => 0])]
    private int $shown = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $proceeded = 0;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function getCashRegisterId(): int { return $this->cashRegisterId; }
    public function getStoreDay(): \DateTimeImmutable { return $this->storeDay; }
    public function getShown(): int { return $this->shown; }
    public function getProceeded(): int { return $this->proceeded; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
