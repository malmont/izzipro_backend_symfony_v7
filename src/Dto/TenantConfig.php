<?php
// src/Dto/TenantConfig.php
namespace App\Dto;

/**
 * DTO simple pour transporter les infos du tenant.
 */
class TenantConfig
{
    private string $code;
    private string $name;
    private string $dbname;

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getDbname(): string
    {
        return $this->dbname;
    }

    public function setDbname(string $dbname): self
    {
        $this->dbname = $dbname;
        return $this;
    }
}
