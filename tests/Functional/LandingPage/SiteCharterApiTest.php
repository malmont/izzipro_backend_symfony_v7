<?php

namespace App\Tests\Functional\LandingPage;

use App\Entity\User;
use App\Services\LandingAiService\LandingAiCatalogue;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeLandingAiClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Charte et brief du site par l'API (08/11/2026) : lecture, écriture contrôlée, document PDF, et relecture automatique par l'assistant à
 * chaque composition (désactivable par useSiteCharter: false).
 */
class SiteCharterApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeLandingAiClient::reset();
        static::getContainer()->get('limiter.landing_ai_tenant')->create('tenant:' . MV_TEST_TENANT_CODE)->reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        $this->db->executeStatement('DELETE FROM site_charter');
        $this->db->executeStatement('DELETE FROM ai_usage');
        $this->db->executeStatement('DELETE FROM ai_job');
        $this->db->executeStatement('DELETE FROM ai_credit_setting');
        $email = 'charter-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Charte')->setLastname('Test')->setRoles(['ROLE_ADMIN', 'ROLE_USER_INTERNET'])->setIsVerified(true)->setPassword('x');
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }

    protected function tearDown(): void
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $db = $provider->getEntityManager()->getConnection();
        $db->executeStatement('DELETE FROM site_charter');
        $db->executeStatement('DELETE FROM ai_usage');
        $db->executeStatement('DELETE FROM ai_job');
        parent::tearDown();
    }

    public function testCharterIsStoredValidatedAndGivenToTheAssistant(): void
    {
        $this->assertNull($this->json('GET', '/api/site/charter')['designMd'], 'rien au départ');

        $bad = $this->request('PUT', '/api/site/charter', ['charter' => ['primaryColor' => 'bleu'], 'style' => ['tone' => 'fort']]);
        $this->assertSame(422, $bad->getStatusCode());
        $this->assertSame(['charter.primaryColor', 'style.tone'], array_column(json_decode($bad->getContent(), true)['errors'], 'path'));

        $saved = $this->json('PUT', '/api/site/charter', ['brief' => ['activity' => 'Boulangerie de quartier', 'pages' => ['Nos pains', 'Contact', 'accueil']], 'charter' => ['primaryColor' => '#123456', 'buttonStyle' => 'outline'], 'style' => ['tone' => 'sobre'], 'designMd' => 'Charte test ABC']);
        $this->assertSame('Charte test ABC', $saved['designMd']);
        $this->assertSame(['Nos pains', 'Contact'], $saved['brief']['pages'], 'l\'accueil n\'est jamais une page en plus');
        $this->assertSame('#123456', $saved['charter']['primaryColor']);
        $this->assertNull($saved['document']);
        $this->assertArrayHasKey('goals', $saved['options']);

        // Document PDF
        $pdf = tempnam(sys_get_temp_dir(), 'charte') . '.pdf';
        file_put_contents($pdf, "%PDF-1.4\n%%EOF\n");
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/site/charter/document', [], ['file' => new UploadedFile($pdf, 'ma charte.pdf', 'application/pdf', null, true)], $this->headers());
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->assertSame('ma charte.pdf', json_decode((string) $this->client->getResponse()->getContent(), true)['document']['name']);
        file_put_contents($pdf, 'pas un pdf');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/site/charter/document', [], ['file' => new UploadedFile($pdf, 'faux.pdf', 'application/pdf', null, true)], $this->headers());
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        @unlink($pdf);

        // Une retouche de section reçoit la charte et le DESIGN.md du site (pas le PDF : une retouche ne lit pas d'images)
        $catalogue = static::getContainer()->get(LandingAiCatalogue::class);
        $composition = json_decode(json_encode($catalogue->preset('group-type-s')[1]['composition'], JSON_PRESERVE_ZERO_FRACTION), false);
        FakeLandingAiClient::$queue[] = FakeLandingAiClient::editResponse([['op' => 'section', 'set' => ['rootGap' => 48]]], 'Espacement réduit.');
        $response = $this->request('POST', '/api/landingpage-ai/compose', ['mode' => 'edit', 'componentKey' => 'PresentationGroup', 'composition' => $composition, 'prompt' => 'Resserre un peu.']);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $sent = json_encode(FakeLandingAiClient::$requests[0], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Charte test ABC', $sent, 'DESIGN.md du site redonné');
        $this->assertStringContainsString('#123456', $sent, 'charte du site redonnée');
        $this->assertStringNotContainsString('application/pdf', $sent, 'pas de PDF pour une retouche');

        FakeLandingAiClient::$queue[] = FakeLandingAiClient::editResponse([['op' => 'section', 'set' => ['rootGap' => 40]]], 'Encore.');
        $response = $this->request('POST', '/api/landingpage-ai/compose', ['mode' => 'edit', 'componentKey' => 'PresentationGroup', 'composition' => $composition, 'prompt' => 'Encore.', 'useSiteCharter' => false]);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertStringNotContainsString('Charte test ABC', json_encode(FakeLandingAiClient::$requests[1], JSON_UNESCAPED_UNICODE), 'useSiteCharter: false');

        $this->client->request('DELETE', 'https://' . MV_TEST_TENANT_HOST . '/api/site/charter/document', [], [], $this->headers());
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertNull($this->json('GET', '/api/site/charter')['document']);

        // Sans jeton : refusé
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/site/charter', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    private function json(string $method, string $path, ?array $body = null): array
    {
        $response = $this->request($method, $path, $body);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        return json_decode((string) $response->getContent(), true);
    }

    private function request(string $method, string $path, ?array $body = null): \Symfony\Component\HttpFoundation\Response
    {
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $this->headers() + ['CONTENT_TYPE' => 'application/json'], $body !== null ? json_encode($body, JSON_PRESERVE_ZERO_FRACTION) : null);

        return $this->client->getResponse();
    }

    private function headers(): array
    {
        return ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token, 'HTTP_ACCEPT' => 'application/json'];
    }
}
