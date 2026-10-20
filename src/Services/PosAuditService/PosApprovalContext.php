<?php

namespace App\Services\PosAuditService;

use Symfony\Contracts\Service\ResetInterface;

/** Validation consommée pendant la requête en cours : l'entrée de journal de l'action protégée porte approvedBy (C3) */
class PosApprovalContext implements ResetInterface
{
    private ?array $approvedBy = null;

    public function set(?array $approvedBy): void
    {
        $this->approvedBy = $approvedBy;
    }

    public function approvedBy(): ?array
    {
        return $this->approvedBy;
    }

    public function reset(): void
    {
        $this->approvedBy = null;
    }
}
