<?php

namespace App\Tests\Unit\Services\SocialNetworkService;

use App\Entity\Entreprise;
use App\Entity\SocialNetwork;
use App\Services\SocialNetworkService\SocialNetworkService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

class SocialNetworkServiceTest extends TestCase
{
    private $emProvider;
    private $entityManager;
    private $repository;
    private $service;

    protected function setUp(): void
    {
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(EntityRepository::class);

        $this->entityManager->method('getRepository')
            ->with(SocialNetwork::class)
            ->willReturn($this->repository);

        $this->emProvider->method('getEntityManager')
            ->willReturn($this->entityManager);

        $this->service = new SocialNetworkService($this->emProvider);
    }

    public function testGetSocialNetworksByEntreprise(): void
    {
        $entreprise = new Entreprise();
        $socialNetwork = new SocialNetwork();

        $this->repository->expects($this->once())
            ->method('findBy')
            ->with(['entreprise' => $entreprise])
            ->willReturn([$socialNetwork]);

        $result = $this->service->getSocialNetworksByEntreprise($entreprise);
        $this->assertCount(1, $result);
        $this->assertSame($socialNetwork, $result[0]);
    }

    public function testUpdateSocialNetworksForEntreprise(): void
    {
        $entreprise = new Entreprise();
        
        $companyData = [
            'website_facebook' => 'https://facebook.com/mycompany',
            'website_instagram' => 'https://instagram.com/mycompany',
            'website_linkedin' => 'https://linkedin.com/company/mycompany',
            'website_twitter' => '#',
            'website_youtube' => '',
            // website_tiktok is omitted to verify it skips correctly
        ];

        // We expect persist calls for the 5 present fields
        $this->entityManager->expects($this->exactly(5))
            ->method('persist')
            ->with($this->isInstanceOf(SocialNetwork::class));

        $this->service->updateSocialNetworksForEntreprise($entreprise, $companyData);

        // Check if they were added to the entreprise
        $socialNetworks = $entreprise->getSocialNetworks();
        $this->assertCount(5, $socialNetworks);

        $mapped = [];
        foreach ($socialNetworks as $sn) {
            $mapped[$sn->getName()] = $sn->getUrl();
        }

        $this->assertEquals('https://facebook.com/mycompany', $mapped['facebook'] ?? null);
        $this->assertEquals('https://instagram.com/mycompany', $mapped['instagram'] ?? null);
        $this->assertEquals('https://linkedin.com/company/mycompany', $mapped['linkedin'] ?? null);
        $this->assertEquals('#', $mapped['twitter'] ?? null);
        $this->assertEquals('', $mapped['youtube'] ?? null);
        $this->assertArrayNotHasKey('tiktok', $mapped);
    }
}
