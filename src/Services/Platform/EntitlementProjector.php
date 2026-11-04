<?php

namespace App\Services\Platform;

use App\Services\FeatureAccess\FeatureCatalog;
use Psr\Log\LoggerInterface;

/**
 * Projection des droits d'usage effectifs d'un site sur les colonnes is_*_active de sa fiche Entreprise (lot 3, 04/11/2026) :
 * les frontaux et le garde-fou des modules continuent de lire ces colonnes, les droits d'usage en sont la source. Écriture SQL
 * directe dans la base du site (aucun écouteur Doctrine, donc aucune boucle avec EntitlementFlagSync) puis cache du site vidé.
 */
class EntitlementProjector
{
    public function __construct(private readonly TenantDatabaseConnector $connector, private readonly LoggerInterface $logger)
    {
    }

    /** @param array<string, bool> $enabled clé gouvernée => droit effectif ; retourne false si le site ou sa fiche manquent */
    public function apply(string $tenantCode, array $enabled): bool
    {
        $tenant = $this->connector->tenant($tenantCode);
        if ($tenant === null) {
            $this->logger->warning(sprintf('[Entitlements] projection : site %s introuvable', $tenantCode));

            return false;
        }
        $sets = [];
        $params = [];
        foreach (FeatureCatalog::GOVERNED as $key => $column) {
            if (!array_key_exists($key, $enabled)) {
                continue;
            }
            $sets[] = "$column = ?";
            $params[] = $enabled[$key] ? 't' : 'f';
        }
        if ($sets === []) {
            return true;
        }
        try {
            $pdo = $this->connector->pdo($tenant['dbname']);
            $count = $pdo->prepare('UPDATE entreprise SET ' . implode(', ', $sets) . ' WHERE id = (SELECT id FROM entreprise ORDER BY id LIMIT 1)');
            $count->execute($params);
            $this->connector->invalidateSiteCache($tenant['code'], $tenant['dbname']);
            if ($count->rowCount() === 0) {
                $this->logger->warning(sprintf('[Entitlements] projection : aucune fiche Entreprise sur %s', $tenantCode));

                return false;
            }
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Entitlements] projection sur %s impossible : %s', $tenantCode, $e->getMessage()));

            return false;
        }

        return true;
    }

    /** @return array<string, bool>|null indicateurs actuels de la fiche Entreprise d'un site (clé gouvernée => valeur), null sans fiche */
    public function currentFlags(string $tenantCode): ?array
    {
        $tenant = $this->connector->tenant($tenantCode);
        if ($tenant === null) {
            return null;
        }
        try {
            $row = $this->connector->pdo($tenant['dbname'])->query('SELECT ' . implode(', ', FeatureCatalog::GOVERNED) . ' FROM entreprise ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('[Entitlements] indicateurs de %s illisibles : %s', $tenantCode, $e->getMessage()));

            return null;
        }
        if (!$row) {
            return null;
        }
        $flags = [];
        foreach (FeatureCatalog::GOVERNED as $key => $column) {
            $flags[$key] = $row[$column] === null ? false : filter_var($row[$column], FILTER_VALIDATE_BOOLEAN);
        }

        return $flags;
    }
}
