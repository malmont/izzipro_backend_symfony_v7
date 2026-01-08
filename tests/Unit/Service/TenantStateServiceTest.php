<?php

namespace App\Tests\Unit\Service;

use App\Services\TenantStateService;
use PHPUnit\Framework\TestCase;

class TenantStateServiceTest extends TestCase
{
    public function testSetAndGetTenantDbName(): void
    {
        $service = new TenantStateService();

        // 1. Verify initial state is null
        $this->assertNull($service->getTenantDbName(), 'Initial state should be null');

        // 2. Set value
        $dbName = 'test_db';
        $service->setTenantDbName($dbName);

        // 3. Verify value is retrieved correctly
        $this->assertEquals($dbName, $service->getTenantDbName(), 'Should return the set database name');
    }
}
