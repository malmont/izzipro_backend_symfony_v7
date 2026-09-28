<?php

namespace App\Tests\Functional\Security;

use App\Entity\SharedMedia;
use App\Services\SharedMedia\SharedMediaTypes;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Délivrance des médias privés : aucun contenu actif (SVG, HTML déguisé) servi comme page web depuis le domaine
 * du backend, pages d'erreur neutres pour les destinataires du lien.
 */
class SharedMediaDeliveryHardeningTest extends WebTestCase
{
    private const HEADERS = ['HTTP_HOST' => MV_TEST_TENANT_HOST, 'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST];

    private KernelBrowser $client;
    private array $createdFiles = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testUploadTypeMustMatchContent(): void
    {
        $this->assertTrue(SharedMediaTypes::isAllowed('pdf', 'application/pdf'));
        $this->assertFalse(SharedMediaTypes::isAllowed('pdf', 'text/html'), 'HTML renommé en .pdf refusé');
        $this->assertFalse(SharedMediaTypes::isAllowed('svg', 'text/html'), 'HTML renommé en .svg refusé');
        $this->assertFalse(SharedMediaTypes::isAllowed('html', 'text/html'), 'HTML non accepté');
        $this->assertFalse(SharedMediaTypes::isAllowed('php', 'text/x-php'), 'script non accepté');
        $this->assertTrue(SharedMediaTypes::isAllowed('DOCX', 'application/zip'), 'docx détecté comme archive accepté');
    }

    public function testRiskyFormatsAreNeverRenderedAsWebPages(): void
    {
        // SVG (peut contenir du script) : toujours en téléchargement
        $key = $this->privateMedia('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml');
        $this->client->request('GET', "/media/secure/$key", [], [], self::HEADERS);
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/svg+xml', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));

        // Ancienne donnée au type enregistré « text/html » : servie d'après l'extension, jamais comme HTML
        $key = $this->privateMedia('rapport.pdf', '<html><script>alert(1)</script></html>', 'text/html');
        $this->client->request('GET', "/media/secure/$key", [], [], self::HEADERS);
        $this->assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));

        // Image affichée dans le navigateur : sandbox CSP, pas de reniflage de type
        $key = $this->privateMedia('photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='), 'image/png');
        $this->client->request('GET', "/media/secure/$key", [], [], self::HEADERS);
        $response = $this->client->getResponse();
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testErrorPagesAreNeutralAndHeadDoesNotCount(): void
    {
        // Lien invalide / expiré : page neutre, sans page de debug ni trace
        $this->client->request('GET', '/media/secure/' . str_repeat('ab', 32), [], [], self::HEADERS);
        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('Document non disponible', $response->getContent());
        $this->assertStringNotContainsString('Symfony', $response->getContent());

        $key = $this->privateMedia('expire.pdf', '%PDF-1.4 test', 'application/pdf', new \DateTimeImmutable('-1 day'));
        $this->client->request('GET', "/media/secure/$key", [], [], self::HEADERS);
        $this->assertSame(410, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('expiré', $this->client->getResponse()->getContent());

        // Une requête HEAD n'incrémente pas le compteur de téléchargements
        $key = $this->privateMedia('compteur.pdf', '%PDF-1.4 test', 'application/pdf');
        $this->client->request('HEAD', "/media/secure/$key", [], [], self::HEADERS);
        $this->client->request('GET', "/media/secure/$key", [], [], self::HEADERS);
        $em = $this->em();
        $media = $em->getRepository(SharedMedia::class)->findOneBy(['accessKey' => $key]);
        $em->refresh($media);
        $this->assertSame(1, $media->getDownloadCount());
    }

    private function privateMedia(string $name, string $content, string $storedMimeType, ?\DateTimeImmutable $expiresAt = null): string
    {
        $dir = static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/private_media';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $filename = 'test_' . bin2hex(random_bytes(6)) . '_' . $name;
        file_put_contents("$dir/$filename", $content);
        $this->createdFiles[] = "$dir/$filename";

        $key = bin2hex(random_bytes(32));
        $media = (new SharedMedia())
            ->setTitre('Test ' . $name)
            ->setFilename($filename)
            ->setOriginalFilename($name)
            ->setMediaType(SharedMedia::TYPE_DOCUMENT)
            ->setMimeType($storedMimeType)
            ->setFileSize(strlen($content))
            ->setVisibility(SharedMedia::VISIBILITY_PRIVATE)
            ->setAccessKey($key);
        if ($expiresAt) {
            $media->setExpiresAt($expiresAt);
        }
        $em = $this->em(); // une seule fois : la bascule de tenant vide le gestionnaire d'entités
        $em->persist($media);
        $em->flush();

        return $key;
    }

    private function em()
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }
}
