<?php
// Le chemin doit être : src/Services/TenantStateService.php

namespace App\Services;

class TenantStateService
{
    private ?string $tenantDbName = null;

    public function setTenantDbName(string $dbName): void
    {
        $this->tenantDbName = $dbName;
    }

    public function getTenantDbName(): ?string
    {
        return $this->tenantDbName;
    }
}