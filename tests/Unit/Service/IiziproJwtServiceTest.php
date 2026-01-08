<?php

namespace App\Tests\Unit\Service;

use App\Services\IiziproJwtService;
use PHPUnit\Framework\TestCase;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class IiziproJwtServiceTest extends TestCase
{
    private string $tempDir;
    private string $privateKeyPath;
    private string $publicKeyPath;

    protected function setUp(): void
    {
        // 1. Setup temporary directory structure mimicking project structure
        // The service looks for: $projectDir . '/config/secrets/test/iizipro_client.key'
        $this->tempDir = sys_get_temp_dir() . '/iizipro_test_' . uniqid();
        mkdir($this->tempDir . '/config/secrets/test', 0777, true);

        $this->privateKeyPath = $this->tempDir . '/config/secrets/test/iizipro_client.key';

        // 2. Generate Key Pair
        $res = openssl_pkey_new([
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($res, $privateKey);
        file_put_contents($this->privateKeyPath, $privateKey);

        $details = openssl_pkey_get_details($res);
        $this->publicKeyPath = $details['key']; // Use string directly
    }

    protected function tearDown(): void
    {
        // Cleanup
        if (file_exists($this->privateKeyPath)) {
            unlink($this->privateKeyPath);
        }
        if (is_dir($this->tempDir . '/config/secrets/test')) {
            rmdir($this->tempDir . '/config/secrets/test');
            rmdir($this->tempDir . '/config/secrets');
            rmdir($this->tempDir . '/config');
            rmdir($this->tempDir);
        }
    }

    public function testGenerateForGemsuiteReturnsValidJwt(): void
    {
        // 1. Instantiate Service with temp project dir
        $service = new IiziproJwtService($this->tempDir);

        // 2. Execute
        $token = $service->generateForGemsuite();

        // 3. Verify
        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        // Decode to verify signature and claims
        $decoded = JWT::decode($token, new Key($this->publicKeyPath, 'RS256'));

        $this->assertEquals('iizipro-bff', $decoded->iss);
        $this->assertEquals('gemsuite-api', $decoded->aud);
        $this->assertEquals('iizipro_tenant_main', $decoded->tnt);

        // Timer checks (approximate)
        $this->assertLessThanOrEqual(time(), $decoded->iat);
        $this->assertGreaterThan(time(), $decoded->exp);
        $this->assertEquals($decoded->iat + 120, $decoded->exp);
    }
}
