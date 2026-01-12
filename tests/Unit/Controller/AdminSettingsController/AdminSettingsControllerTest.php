<?php

namespace App\Tests\Unit\Controller\AdminSettingsController;

use App\Controller\AdminSettingsController\AdminSettingsController;
use App\Entity\AdminSettings;
use App\Services\TenantCacheService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Cache\ItemInterface;

class AdminSettingsControllerTest extends TestCase
{
    private $tenantEmProvider;
    private $cacheService;
    private $controller;
    private $entityManager;
    private $container;

    protected function setUp(): void
    {
        $this->tenantEmProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->cacheService = $this->createMock(TenantCacheService::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->tenantEmProvider->method('getEntityManager')->willReturn($this->entityManager);

        $this->controller = new AdminSettingsController(
            $this->tenantEmProvider,
            $this->cacheService
        );

        $this->container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $this->controller->setContainer($this->container);

        // Default container to prevent errors checks
        $this->container->method('has')->willReturn(false);
    }

    public function testGetAdminSettingsSuccess(): void
    {
        // Mock Cache Behavior
        $settings = new AdminSettings();
        $settings->setThemeChoice('dark');
        $settings->setNavbarComponent('default_nav');
        $settings->setStyleChoice('default_style');

        $this->cacheService->expects($this->once())
            ->method('get')
            ->with('admin_settings', $this->anything())
            ->willReturn($settings);

        $response = $this->controller->getAdminSettings();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
    }

    public function testUpdateAdminSettingsSuccess(): void
    {
        $requestData = [
            'themeChoice' => 'light'
        ];
        $request = new Request([], [], [], [], [], [], json_encode($requestData));

        $settings = new AdminSettings();
        $settings->setThemeChoice('dark');
        $settings->setNavbarComponent('default_nav');
        $settings->setStyleChoice('default_style');

        $repo = $this->createMock(EntityRepository::class);
        $this->entityManager->method('getRepository')->with(AdminSettings::class)->willReturn($repo);

        // Controller uses ->find(1)
        $repo->method('find')->with(1)->willReturn($settings);

        $this->entityManager->expects($this->once())->method('flush');
        $this->cacheService->expects($this->once())->method('delete')->with('admin_settings');

        $response = $this->controller->updateAdminSettings($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $this->assertEquals('light', $settings->getThemeChoice());
    }
}
