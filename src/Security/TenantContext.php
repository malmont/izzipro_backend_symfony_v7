<?php
// src/Security/TenantContext.php

namespace App\Security;

use App\Dto\TenantConfig;
use Symfony\Component\HttpFoundation\RequestStack;
use App\Services\TenantConnectionManager;

class TenantContext
{
    private ?TenantConfig $tenant      = null;
    private bool          $initialized = false;

    public function __construct(
        private RequestStack $requestStack,
        private TenantConnectionManager $manager
    ) {}

    public function getTenant(): ?TenantConfig
    {
        if ($this->initialized) {
            return $this->tenant;
        }
        $this->initialized = true;

        $req = $this->requestStack->getCurrentRequest();
        if (!$req) {
            return null;
        }

        // 1) Récupération du code
        $host = $req->getHost();
        $code = $req->headers->get('X-Tenant-Code')
             ?: (\str_contains($host, '.') ? \explode('.', $host, 2)[0] : null);
        if (!$code) {
            return null;
        }

        // 2) Exposition de PDO Master via un getter (à ajouter dans TenantConnectionManager)
        $pdo = $this->manager->getPdoMaster();

        // 3) Requête sécurisée
        $stmt = $pdo->prepare('SELECT code, name, dbname FROM tenants WHERE code = :c');
        $stmt->execute(['c' => $code]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        // 4) Hydratation
        $tc = new TenantConfig();
        $tc->setCode($row['code']);
        $tc->setName($row['name']);
        $tc->setDbname($row['dbname']);

        return $this->tenant = $tc;
    }
}
