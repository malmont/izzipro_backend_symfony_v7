<?php

namespace App\Tests\Unit\Service;

use App\Entity\Carrier;
use App\Entity\Feature;
use App\Services\DefaultAssetSynchronizer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

class DefaultAssetSynchronizerTest extends TestCase
{
    private $filesystem;
    private $kernel;
    private $tenantEm;
    private $synchronizer;
    private $projectDir;

    protected function setUp(): void
    {
        $this->filesystem = $this->createMock(Filesystem::class);
        $this->kernel = $this->createMock(KernelInterface::class);
        $this->tenantEm = $this->createMock(EntityManagerInterface::class);

        $this->projectDir = '/var/www/project';
        $this->kernel->method('getProjectDir')->willReturn($this->projectDir);

        $this->synchronizer = new DefaultAssetSynchronizer($this->filesystem, $this->kernel);
    }

    public function testSynchronizeCarriersCopiesFilesAndUpdatesEntity(): void
    {
        $carrier = new Carrier();
        $carrier->setPhoto('original.jpg');

        $carrierRepo = $this->createMock(EntityRepository::class);
        $carrierRepo->method('findAll')->willReturn([$carrier]);

        $featureRepo = $this->createMock(EntityRepository::class);
        $featureRepo->method('findAll')->willReturn([]);

        // Setup repository map
        $this->tenantEm->method('getRepository')->will($this->returnValueMap([
            [Carrier::class, $carrierRepo],
            [Feature::class, $featureRepo],
        ]));

        // Mock Filesystem
        $masterPath = $this->projectDir . '/public/assets/master_files/Carrier/original.jpg';

        $this->filesystem->method('exists')
            ->willReturnCallback(function ($path) use ($masterPath) {
                return $path === $masterPath;
            });

        $this->filesystem->expects($this->once())
            ->method('copy')
            ->with(
                $masterPath,
                $this->callback(function ($dest) {
                    return str_contains($dest, '/public/assets/uploads/Carrier/') && str_ends_with($dest, '.jpg');
                })
            );

        $this->synchronizer->synchronize($this->tenantEm);

        $this->assertNotEquals('original.jpg', $carrier->getPhoto());
        $this->assertStringEndsWith('.jpg', $carrier->getPhoto());
        $this->assertEquals(44, strlen($carrier->getPhoto())); // 40 chars sha1 + 4 chars .jpg
    }

    public function testSynchronizeFeaturesCopiesFilesAndUpdatesEntity(): void
    {
        $feature = new Feature();
        $feature->setIconpath('icon.png');

        $featureRepo = $this->createMock(EntityRepository::class);
        $featureRepo->method('findAll')->willReturn([$feature]);

        $carrierRepo = $this->createMock(EntityRepository::class);
        $carrierRepo->method('findAll')->willReturn([]);

        $this->tenantEm->method('getRepository')->will($this->returnValueMap([
            [Carrier::class, $carrierRepo],
            [Feature::class, $featureRepo],
        ]));

        $masterPath = $this->projectDir . '/public/assets/master_files/Feature/icon.png';

        $this->filesystem->method('exists')
            ->willReturnCallback(function ($path) use ($masterPath) {
                return $path === $masterPath;
            });

        $this->filesystem->expects($this->once())
            ->method('copy')
            ->with(
                $masterPath,
                $this->callback(function ($dest) {
                    return str_contains($dest, '/public/assets/uploads/icons/') && str_ends_with($dest, '.png');
                })
            );

        $this->synchronizer->synchronize($this->tenantEm);

        $this->assertNotEquals('icon.png', $feature->getIconpath());
        $this->assertStringEndsWith('.png', $feature->getIconpath());
    }

    public function testSynchronizeDoesNothingIfFileMissing(): void
    {
        $carrier = new Carrier();
        $carrier->setPhoto('missing.jpg');

        $carrierRepo = $this->createMock(EntityRepository::class);
        $carrierRepo->method('findAll')->willReturn([$carrier]);

        $featureRepo = $this->createMock(EntityRepository::class);
        $featureRepo->method('findAll')->willReturn([]);

        $this->tenantEm->method('getRepository')->will($this->returnValueMap([
            [Carrier::class, $carrierRepo],
            [Feature::class, $featureRepo],
        ]));

        $this->filesystem->method('exists')->willReturn(false);
        $this->filesystem->expects($this->never())->method('copy');

        $this->synchronizer->synchronize($this->tenantEm);

        $this->assertEquals('missing.jpg', $carrier->getPhoto());
    }
}
