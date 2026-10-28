<?php

namespace App\Tests\Functional\Cache;

use App\MemoiresVivantes\Services\AiQuotaService;
use App\MemoiresVivantes\Services\PricingService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Réglages de Mémoires Vivantes gardés en mémoire (lot 5, 28/10/2026) : un worker qui enchaîne des messages de plusieurs
 * sites, ou une requête qui bascule de site, doit lire les réglages du site courant (avant : clé commune à tous les
 * sites, les réglages du premier site servaient aux suivants), et une remise à zéro entre deux messages (kernel.reset)
 * relit la base. Le limiteur de débit a son propre pool, hors du cache applicatif à étiquettes.
 */
class InMemorySettingsPerSiteTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    private function switchTo(int $site): void
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant($site === 1 ? MV_TEST_TENANT_DB : MV_TEST_TENANT2_DB, $site === 1 ? MV_TEST_TENANT_CODE : MV_TEST_TENANT2_CODE);
    }

    public function testAiQuotaSettingsAreKeptPerSiteAndReReadAfterAReset(): void
    {
        $quotas = static::getContainer()->get(AiQuotaService::class);
        $this->switchTo(1);
        $quotas->save($quotas->settings()->setApplyToWebBooks(true)->setTranscriptionsPerDay(11));
        $this->switchTo(2);
        $quotas->save($quotas->settings()->setApplyToWebBooks(false)->setTranscriptionsPerDay(22));

        $this->switchTo(1);
        $this->assertSame([true, 11], [$quotas->settings()->isApplyToWebBooks(), $quotas->settings()->getTranscriptionsPerDay()], 'site 1 : ses réglages');
        $this->switchTo(2);
        $this->assertSame([false, 22], [$quotas->settings()->isApplyToWebBooks(), $quotas->settings()->getTranscriptionsPerDay()], 'site 2 : ses réglages, pas ceux du site lu avant');

        // modification en base hors du service (autre processus, console) : relue après la remise à zéro du kernel
        $this->switchTo(1);
        static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->getConnection()
            ->executeStatement('UPDATE mv_ai_quota_setting SET transcriptions_per_day = 33');
        $this->assertSame(11, $quotas->settings()->getTranscriptionsPerDay(), 'dans le même traitement, la valeur lue est gardée');
        static::getContainer()->get('services_resetter')->reset();
        $this->switchTo(1);
        $this->assertSame(33, $quotas->settings()->getTranscriptionsPerDay(), 'après kernel.reset (nouveau message, nouvelle requête) : relue en base');
    }

    public function testPricingGridsAreKeptPerSite(): void
    {
        $pricing = static::getContainer()->get(PricingService::class);
        $this->switchTo(1);
        $pricing->save(['CAD' => ['firstCopy' => 111.0, 'nextCopies' => 11.0]]);
        $this->switchTo(2);
        $pricing->save(['CAD' => ['firstCopy' => 222.0, 'nextCopies' => 22.0]]);

        $this->switchTo(1);
        $this->assertSame(111.0, $pricing->settings()->getPrintGrid()['cad']['firstCopy']);
        $this->switchTo(2);
        $this->assertSame(222.0, $pricing->settings()->getPrintGrid()['cad']['firstCopy']);
    }

    public function testTheRateLimiterHasItsOwnPoolOutsideTheTaggedApplicationCache(): void
    {
        /** @var ContainerInterface $container */
        $container = static::getContainer();
        $limiterPool = $container->get('cache.rate_limiter');
        $this->assertNotInstanceOf(TagAwareAdapterInterface::class, $limiterPool, 'le limiteur ne doit jamais être vidé par une invalidation d\'étiquettes');
        $this->assertNotSame($container->get('cache.app'), $limiterPool);
        $item = $limiterPool->getItem('cache_test_' . bin2hex(random_bytes(3)));
        $item->set(1)->expiresAfter(5);
        $this->assertTrue($limiterPool->save($item), 'le pool du limiteur écrit et lit (repli sur REDIS_URL sans REDIS_LIMITER_URL)');
    }
}
