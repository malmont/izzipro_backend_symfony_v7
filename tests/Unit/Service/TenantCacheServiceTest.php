<?php

namespace App\Tests\Unit\Service;

use App\Services\TenantCacheService;
use App\Services\TenantConnectionProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class TenantCacheServiceTest extends TestCase
{
    public function testGetUsesTenantPrefixAndTags(): void
    {
        // 1. Mock dependencies
        $tcp = $this->createMock(TenantConnectionProvider::class);
        $tcp->method('getTenantCode')->willReturn('TENANT_A');

        $cache = $this->createMock(TagAwareCacheInterface::class);

        // 2. Expectation
        // The key should be prefixed: 'TENANT_A:my_key'
        $cache->expects($this->once())
            ->method('get')
            ->with(
                'TENANT_A:my_key',
                $this->anything() // The callback
            )
            ->willReturnCallback(function ($key, $callback) {
                // Determine what the callback does
                $item = $this->createMock(ItemInterface::class);

                // Expect tagging with tenant code
                $item->expects($this->once())
                    ->method('tag')
                    ->with($this->callback(function ($tags) {
                        return in_array('TENANT_A', $tags);
                    }));

                return $callback($item);
            });

        // 3. Execution
        $service = new TenantCacheService($cache, $tcp);
        $result = $service->get('my_key', function () {
            return 'computed_value';
        });

        // 4. Assertion
        $this->assertEquals('computed_value', $result);
    }

    public function testGetUsesMasterPrefixWhenNoTenant(): void
    {
        $tcp = $this->createMock(TenantConnectionProvider::class);
        $tcp->method('getTenantCode')->willReturn(null);

        $cache = $this->createMock(TagAwareCacheInterface::class);

        // Expect 'master:' prefix
        $cache->expects($this->once())
            ->method('get')
            ->with('master:global_key', $this->anything())
            ->willReturn('global_value');

        $service = new TenantCacheService($cache, $tcp);
        $result = $service->get('global_key', fn() => 'global_value');

        $this->assertEquals('global_value', $result);
    }

    public function testGetAppliesTtlAndExtraTags(): void
    {
        $tcp = $this->createMock(TenantConnectionProvider::class);
        $tcp->method('getTenantCode')->willReturn('TENANT_B');

        $cache = $this->createMock(TagAwareCacheInterface::class);

        $cache->expects($this->once())
            ->method('get')
            ->willReturnCallback(function ($key, $callback) {
                $item = $this->createMock(ItemInterface::class);

                // Check TTL
                $item->expects($this->once())->method('expiresAfter')->with(3600);

                // Check Tags: should include tenant code + prefixed extra tags
                // 'TENANT_B', 'TENANT_Bcustom_tag'
                $item->expects($this->once())->method('tag')
                    ->with($this->callback(function ($tags) {
                        return in_array('TENANT_B', $tags)
                            && in_array('TENANT_Bcustom_tag', $tags);
                    }));

                return $callback($item);
            });

        $service = new TenantCacheService($cache, $tcp);
        $service->get('cached_item', fn() => 'val', 3600, ['custom_tag']);
    }
}
