<?php

namespace App\Services\PosAuditService;

use Symfony\Contracts\Service\ResetInterface;

/** Validation consommée pendant la requête en cours : l'entrée de journal de l'action protégée porte approvedBy (C3) */
class PosApprovalContext implements ResetInterface
{
    private ?array $approvedBy = null;
    /** Validation consommée par cette requête : {id, actions [{action, context}]} ; ses autres actions restent couvertes (jeton groupé, P2-a) */
    private ?array $consumed = null;

    public function set(?array $approvedBy): void
    {
        $this->approvedBy = $approvedBy;
    }

    public function setConsumed(?array $consumed): void
    {
        $this->consumed = $consumed;
    }

    public function consumed(): ?array
    {
        return $this->consumed;
    }

    public function approvedBy(): ?array
    {
        return $this->approvedBy;
    }

    public function reset(): void
    {
        $this->approvedBy = null;
        $this->consumed = null;
    }
}
