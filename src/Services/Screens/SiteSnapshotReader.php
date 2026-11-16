<?php

namespace App\Services\Screens;

use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\Platform\TenantDatabaseConnector;
use App\Services\Storefront\StorefrontService;
use Psr\Log\LoggerInterface;

/**
 * Ce qu'un menu doit savoir d'un site DÉSIGNÉ, lu dans sa base sans changer le site de la requête (lot AG-3a) : son état (ouvert ou en
 * construction) et ses modules actifs. Une seule connexion par site, deux lectures de sa fiche, aucune écriture.
 */
class SiteSnapshotReader
{
    public function __construct(private readonly TenantDatabaseConnector $connector, private readonly StorefrontService $storefront, private readonly LoggerInterface $logger)
    {
    }

    /**
     * @return array{state: ?string, scope: ?string, modules: array<string, bool>} un site illisible : état inconnu (null), aucun module
     */
    public function read(string $code): array
    {
        $unknown = ['state' => null, 'scope' => null, 'modules' => array_fill_keys(array_keys(FeatureCatalog::GOVERNED), false)];
        try {
            $site = $this->connector->tenant($code);
            if ($site === null) {
                return $unknown;
            }
            $pdo = $this->connector->pdo($site['dbname']);
            $view = $this->storefront->viewOf(['code' => $site['code'], 'dbname' => $site['dbname']], $pdo);
            $row = $pdo->query('SELECT ' . implode(', ', FeatureCatalog::GOVERNED) . ' FROM entreprise ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC) ?: [];
            $modules = [];
            foreach (FeatureCatalog::GOVERNED as $key => $column) {
                $modules[$key] = in_array($row[$column] ?? false, [true, 't', 'true', 1, '1'], true);
            }

            return ['state' => $view['state'], 'scope' => $view['scope'], 'modules' => $modules];
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('[Contextes] site %s illisible (%s) : état inconnu', $code, $e::class));

            return $unknown;
        }
    }
}
