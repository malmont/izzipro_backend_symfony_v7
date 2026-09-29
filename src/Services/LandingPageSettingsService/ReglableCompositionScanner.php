<?php

namespace App\Services\LandingPageSettingsService;

use App\Entity\LandingPageSetting;
use App\Services\TenantConnectionManager;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;

/**
 * Parcourt les compositions réglables enregistrées de toutes les bases tenant (lecture seule), puis revient au tenant
 * de départ : utilisable pendant une requête HTTP comme dans une commande.
 */
class ReglableCompositionScanner
{
    public function __construct(
        private readonly TenantConnectionManager $connectionManager,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionProvider $tenantProvider,
        private readonly ReglableCompositionValidator $validator
    ) {
    }

    /**
     * @return list<array{database: string, tenants: list<string>, compositions: array<string, mixed>, error: ?string}>
     *         une entrée par base ; error si la base n'a pas pu être lue
     */
    public function scan(): array
    {
        $rows = $this->connectionManager->getPdoMaster()
            ->query("SELECT dbname, string_agg(code, ',' ORDER BY code) AS codes FROM tenants GROUP BY dbname ORDER BY dbname")
            ->fetchAll(\PDO::FETCH_ASSOC);

        $connection = $this->emProvider->getEntityManager()->getConnection();
        $originalDb = $connection->getParams()['dbname'] ?? null;
        $originalCode = $this->tenantProvider->getTenantCode();

        $databases = [];
        try {
            foreach ($rows as $row) {
                $entry = ['database' => $row['dbname'], 'tenants' => explode(',', (string) $row['codes']), 'compositions' => [], 'error' => null];
                try {
                    $this->emProvider->switchTenant($row['dbname'], $entry['tenants'][0]);
                    $em = $this->emProvider->getEntityManager();
                    $table = $em->getClassMetadata(LandingPageSetting::class)->getTableName();
                    $raw = $em->getConnection()->fetchOne("SELECT configuration FROM $table ORDER BY id LIMIT 1");
                    $entry['compositions'] = is_string($raw) ? $this->validator->compositions(json_decode($raw, false)) : [];
                } catch (\Throwable $e) {
                    $entry['error'] = $e->getMessage();
                }
                $databases[] = $entry;
            }
        } finally {
            if (is_string($originalDb)) {
                $this->emProvider->switchTenant($originalDb, $originalCode);
            }
        }

        return $databases;
    }
}
