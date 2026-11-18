<?php

namespace App\Tests\Functional\Group;

use App\Gestion\UseCase\GestionDayUseCase;
use App\Gestion\UseCase\GestionStatisticsUseCase;
use App\Services\Group\AllShops\GroupScope;
use App\Services\TenantCacheService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Lot G-2 : garde-fou de l'empreinte du cache de « Toutes les boutiques ». La part d'une boutique reste en cache 5 minutes, et sa clé porte
 * le compteur d'écritures des tables que lit la route (`GroupScope::TABLES`). Une table lue ABSENTE de la liste laisserait une part périmée
 * après une écriture dans cette table : cet essai lit la route dans une transaction, relève les tables qu'elle a lues
 * (`pg_stat_xact_user_tables`, propre à la transaction) et exige qu'elles soient toutes dans la liste. Cache du site vidé avant : une
 * lecture servie par le cache ne cacherait pas sa table.
 */
class GroupScopeReadTablesTest extends KernelTestCase
{
    public function testEveryTableReadByACachedRouteIsInItsFingerprint(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $statistics = $container->get(GestionStatisticsUseCase::class);
        $day = $container->get(GestionDayUseCase::class);
        $reads = [
            'gestion_statistiques' => [
                fn () => $statistics->overviewWithSums(['range' => 'today'], 'fr'),
                fn () => $statistics->overviewWithSums(['range' => 'month'], 'fr'),
                fn () => $statistics->overviewWithSums(['range' => 'year', 'source' => 'pos'], 'en'),
                fn () => $statistics->overviewWithSums(['from' => '2025-01-01', 'to' => '2025-12-31', 'source' => 'ecommerce'], 'fr'),
            ],
            'gestion_journee' => [fn () => $day->overview()],
            'gestion_statistiques_annee' => [fn () => $container->get(\App\Gestion\Services\GestionYearService::class)->overview([], 'fr'),
                fn () => $container->get(\App\Gestion\Services\GestionYearService::class)->overview(['year' => (string) ((int) date('Y') - 1)], 'en')],
            'gestion_groupe_resultat' => [fn () => $container->get(\App\Gestion\Services\PeriodResultCalculator::class)->compute(
                (new \App\Entity\Collections())->setNomCollection('essai')->setStartDateCollection(new \DateTime('-40 days'))->setEndDateCollection(new \DateTime('-1 day')), null)],
        ];
        $this->assertSame(array_keys(array_filter(GroupScope::ROUTES, static fn (array $r): bool => $r['cache'])), array_keys($reads), 'chaque route en cache est mesurée ici');
        $missing = [];
        foreach ($reads as $route => $calls) {
            $read = [];
            foreach ($calls as $call) {
                $container->get(TenantCacheService::class)->invalidateSite(MV_TEST_TENANT_CODE, MV_TEST_TENANT_DB);
                $conn = $provider->getEntityManager()->getConnection();
                $conn->beginTransaction();
                try {
                    $call();
                    $read = [...$read, ...$conn->fetchFirstColumn('SELECT relname FROM pg_stat_xact_user_tables WHERE COALESCE(seq_scan, 0) + COALESCE(idx_scan, 0) > 0')];
                } finally {
                    $conn->rollBack();
                }
            }
            $read = array_values(array_unique($read));
            sort($read);
            $this->assertNotSame([], $read, $route);
            foreach (array_diff($read, GroupScope::TABLES[$route]) as $table) {
                $missing[] = "$route : $table";
            }
        }
        $this->assertSame([], $missing, 'tables lues absentes de GroupScope::TABLES (une écriture n\'y périmerait pas la part en cache)');
    }
}
