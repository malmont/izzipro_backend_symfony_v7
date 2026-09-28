<?php

namespace App\Tests\Functional\Security;

use App\Entity\SharedMedia;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SharedMediaSecurityTest extends WebTestCase
{
    private ?string $privateStorageDir = null;
    /** Fichiers créés dans le vrai dossier de stockage : supprimés même si le test échoue */
    private array $createdFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $file) {
            @unlink($file);
        }
        $this->createdFiles = [];
        parent::tearDown();
    }
    private ?string $publicStorageDir = null;

    private function initStorageDirs(): void
    {
        if ($this->privateStorageDir === null) {
            $projectDir = static::getContainer()->getParameter('kernel.project_dir');
            $this->privateStorageDir = $projectDir . '/var/storage/private_media';
            $this->publicStorageDir = $projectDir . '/var/storage/public_bucket/assets/uploads/shared';

            if (!is_dir($this->privateStorageDir)) {
                mkdir($this->privateStorageDir, 0777, true);
            }
            if (!is_dir($this->publicStorageDir)) {
                mkdir($this->publicStorageDir, 0777, true);
            }
        }
    }

    private function getEntityManager(): EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        return $provider->getEntityManager();
    }

    public function testPrivateMediaDeliveryWithValidKey(): void
    {
        $client = static::createClient();
        $this->initStorageDirs();
        $em = $this->getEntityManager();

        // 1. Create a dummy file in private storage
        $filename = 'test_doc_' . bin2hex(random_bytes(6)) . '.pdf';
        $filePath = $this->privateStorageDir . '/' . $filename;
        file_put_contents($filePath, "%PDF-1.4 Fake PDF Content for Unit Test");
        $this->createdFiles[] = $filePath;

        // 2. Persist private SharedMedia entity
        $accessKey = bin2hex(random_bytes(32));
        $media = new SharedMedia();
        $media->setTitre('Test Document Secret');
        $media->setFilename($filename);
        $media->setOriginalFilename('Secret_Agreement.pdf');
        $media->setMediaType(SharedMedia::TYPE_DOCUMENT);
        $media->setMimeType('application/pdf');
        $media->setFileSize(filesize($filePath));
        $media->setVisibility(SharedMedia::VISIBILITY_PRIVATE);
        $media->setAccessKey($accessKey);

        $em->persist($media);
        $em->flush();
        $mediaId = $media->getId();

        $headers = [
            'HTTP_HOST' => MV_TEST_TENANT_HOST,
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST,
        ];

        // 3. Request with valid key
        $client->request('GET', '/media/secure/' . $accessKey, [], [], $headers);
        $response = $client->getResponse();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Secret_Agreement.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        // 4. Request with invalid key -> 403 Forbidden
        $client->request('GET', '/media/secure/invalid_access_token_abc123', [], [], $headers);
        $this->assertSame(403, $client->getResponse()->getStatusCode());

        // 5. Test expired media -> 410 Gone
        $freshEm = $this->getEntityManager();
        $freshMedia = $freshEm->getRepository(SharedMedia::class)->find($mediaId);
        $freshMedia->setExpiresAt(new \DateTimeImmutable('-1 hour'));
        $freshEm->flush();

        $client->request('GET', '/media/secure/' . $accessKey, [], [], $headers);
        $this->assertSame(410, $client->getResponse()->getStatusCode());

        // Cleanup
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
        $cleanupEm = $this->getEntityManager();
        $toDelete = $cleanupEm->getRepository(SharedMedia::class)->find($mediaId);
        if ($toDelete) {
            $cleanupEm->remove($toDelete);
            $cleanupEm->flush();
        }
    }

    public function testPublicMediaListingExcludesPrivateMedia(): void
    {
        $client = static::createClient();
        $this->initStorageDirs();
        $em = $this->getEntityManager();

        // 1. Create a public media
        $publicMedia = new SharedMedia();
        $publicMedia->setTitre('Public Brochure');
        $publicMedia->setFilename('public_sample.pdf');
        $publicMedia->setOriginalFilename('public_sample.pdf');
        $publicMedia->setMediaType(SharedMedia::TYPE_DOCUMENT);
        $publicMedia->setMimeType('application/pdf');
        $publicMedia->setVisibility(SharedMedia::VISIBILITY_PUBLIC);
        $em->persist($publicMedia);

        // 2. Create a private media
        $privateMedia = new SharedMedia();
        $privateMedia->setTitre('Private Contract');
        $privateMedia->setFilename('private_sample.pdf');
        $privateMedia->setOriginalFilename('private_sample.pdf');
        $privateMedia->setMediaType(SharedMedia::TYPE_DOCUMENT);
        $privateMedia->setMimeType('application/pdf');
        $privateMedia->setVisibility(SharedMedia::VISIBILITY_PRIVATE);
        $privateMedia->regenerateAccessKey();
        $em->persist($privateMedia);

        $em->flush();
        $publicId = $publicMedia->getId();
        $privateId = $privateMedia->getId();

        $headers = [
            'HTTP_HOST' => MV_TEST_TENANT_HOST,
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST,
            'HTTP_ACCEPT' => 'application/json',
        ];

        // 3. Request /api/shared-media
        $client->request('GET', '/api/shared-media', [], [], $headers);
        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);

        $titles = array_column($data, 'titre');
        $this->assertContains('Public Brochure', $titles);
        $this->assertNotContains('Private Contract', $titles);

        // 4. Request /api/shared-media/{privateId} should return 404
        $client->request('GET', '/api/shared-media/' . $privateId, [], [], $headers);
        $this->assertSame(404, $client->getResponse()->getStatusCode());

        // Cleanup
        $cleanupEm = $this->getEntityManager();
        $p1 = $cleanupEm->getRepository(SharedMedia::class)->find($publicId);
        $p2 = $cleanupEm->getRepository(SharedMedia::class)->find($privateId);
        if ($p1) {
            $cleanupEm->remove($p1);
        }
        if ($p2) {
            $cleanupEm->remove($p2);
        }
        $cleanupEm->flush();
    }
}
