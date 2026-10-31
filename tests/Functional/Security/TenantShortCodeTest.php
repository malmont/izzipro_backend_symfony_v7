<?php

namespace App\Tests\Functional\Security;

use App\Command\TenantShortCodesCommand;
use App\Services\TenantConnectionManager;
use App\Services\TenantShortCode;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;

/**
 * Codes courts des sites (31/10/2026) : génération, attribution idempotente, résolution par GET /api/tenant/check-mobile
 * après la recherche exacte (inchangée), tolérance Crockford, limiteur par IP, aucun échec mis en cache.
 */
class TenantShortCodeTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $ip;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->ip = sprintf('10.%d.%d.%d', random_int(1, 254), random_int(1, 254), random_int(1, 254)); // limiteur par IP : IP propre à chaque test
    }

    public function testGenerationAlphabetNormalizationAndFormat(): void
    {
        $seen = [];
        for ($i = 0; $i < 500; $i++) {
            $code = TenantShortCode::generate();
            $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{6}$/', $code, 'six caractères Crockford, sans I, L, O ni U');
            $this->assertTrue(TenantShortCode::isValid($code));
            $seen[$code] = true;
        }
        $this->assertGreaterThan(490, \count($seen), 'tirage aléatoire : pas une suite');
        $this->assertSame('DEM042', TenantShortCode::normalize('demo42'));
        $this->assertSame('DEM042', TenantShortCode::normalize('DEMO-42'));
        $this->assertSame('DEM042', TenantShortCode::normalize(' dem 042 '));
        $this->assertSame('MVT001', TenantShortCode::normalize('mvt-ooi'), 'O lu 0, I lu 1');
        $this->assertSame('MVT011', TenantShortCode::normalize('MVTOLI'), 'L lu 1');
        $this->assertNull(TenantShortCode::normalize('MVTU01'), 'U : hors alphabet');
        $this->assertNull(TenantShortCode::normalize('MVT01'), 'cinq caractères');
        $this->assertNull(TenantShortCode::normalize('MVT0011'), 'sept caractères');
        $this->assertSame('MVTEST', TenantShortCode::normalize('mvtest'), 'un code de site de six lettres ressemble à un code court : la recherche exacte passe avant, il n\'est jamais lu comme tel');
        $this->assertNull(TenantShortCode::normalize('MVT-0?1'));
        $this->assertSame('ABC-D4F', TenantShortCode::format('ABCD4F'));
        $this->assertNull(TenantShortCode::format(null));
        $this->assertSame('DEM042', TenantShortCode::FIXED['demo']);
    }

    public function testAssignmentIsUniqueRetriesOnCollisionAndIsIdempotent(): void
    {
        $manager = static::getContainer()->get(TenantConnectionManager::class);
        $pdo = $manager->getPdoMaster();
        $site = 'mvtest-short-' . bin2hex(random_bytes(2));
        $demo = 'demo';
        $pdo->prepare('INSERT INTO tenants (code, name, dbname) VALUES (?, ?, ?)')->execute([$site, 'Site code court', MV_TEST_TENANT_DB]);
        $pdo->prepare('INSERT INTO tenants (code, name, dbname) VALUES (?, ?, ?)')->execute([$demo, 'Demo', MV_TEST_TENANT_DB]);
        try {
            $this->assertNull($manager->shortCodeOf($site));
            // Collision simulée : le premier tirage rend le code du site de test, déjà pris → relance
            $draws = ['MVT001', 'MVT001', 'ZZZ999'];
            $code = $manager->assignShortCode($site, false, function () use (&$draws): string { return array_shift($draws); });
            $this->assertSame('ZZZ999', $code, 'les codes déjà pris sont ignorés');
            $this->assertSame('ZZZ999', $manager->shortCodeOf($site));
            $this->assertSame('ZZZ999', $manager->assignShortCode($site), 'sans force, le code existant ne bouge pas');

            // Attribution des manquants : idempotente, demo reçoit son code fixe
            $first = $manager->assignMissingShortCodes();
            $this->assertSame(['assigned' => 1, 'missing' => 0], ['assigned' => $first['assigned'], 'missing' => $first['missing']]);
            $this->assertSame('DEM042', $manager->shortCodeOf($demo));
            $second = $manager->assignMissingShortCodes();
            $this->assertSame(0, $second['assigned'], 'second passage : rien à faire');
            $this->assertSame('DEM042', $manager->shortCodeOf($demo));
            $this->assertSame('MVT001', $manager->shortCodeOf(MV_TEST_TENANT_CODE), 'les codes existants sont intacts');

            // La commande : même résultat, sortie lisible
            $tester = new CommandTester(new TenantShortCodesCommand($manager));
            $this->assertSame(0, $tester->execute([]));
            $this->assertStringContainsString('sites encore sans code : 0', $tester->getDisplay());
            $this->assertStringContainsString('DEM-042', $tester->getDisplay());

            // Régénération : nouveau code, l'ancien ne résout plus
            $tester->execute(['--regenerate' => $site]);
            $new = $manager->shortCodeOf($site);
            $this->assertNotSame('ZZZ999', $new);
            $this->assertTrue(TenantShortCode::isValid($new));
            $this->assertNull($manager->findTenantConfigByShortCode('ZZZ999'));
            $this->assertSame($site, $manager->findTenantConfigByShortCode($new)?->getCode());
        } finally {
            $pdo->prepare('DELETE FROM tenants WHERE code IN (?, ?)')->execute([$site, $demo]);
            static::getContainer()->get('cache.app')->invalidateTags(['tenants']);
        }
    }

    public function testCheckMobileResolvesShortCodesAfterTheExactLookupAndNeverCachesFailures(): void
    {
        $expected = ['code' => MV_TEST_TENANT_CODE, 'name' => 'Tenant de test', 'host' => MV_TEST_TENANT_HOST, 'shortCode' => 'MVT001'];
        // Recherche exacte inchangée, avec le champ shortCode en plus
        $this->assertSame($expected, json_decode($this->check(MV_TEST_TENANT_CODE)->getContent(), true)['tenant']);
        $this->assertSame($expected, json_decode($this->check(MV_TEST_TENANT_HOST)->getContent(), true)['tenant']);
        // Code court : avec et sans tiret, minuscules, O et I corrigés
        foreach (['MVT001', 'MVT-001', 'mvt001', 'mvt-ooi', ' MVT 0O1 '] as $input) {
            $response = $this->check($input);
            $this->assertSame(200, $response->getStatusCode(), $input . ' : ' . $response->getContent());
            $body = json_decode($response->getContent(), true);
            $this->assertTrue($body['exists']);
            $this->assertSame($expected, $body['tenant'], $input);
        }
        // Inconnu, avec U, trop court : 404 comme un code inconnu, jamais 400
        foreach (['ZZZZZ9', 'MVTU01', 'MVT01', 'MVT0011', 'mvt-0?1'] as $input) {
            $response = $this->check($input);
            $this->assertSame(404, $response->getStatusCode(), $input);
            $this->assertSame(['exists' => false, 'error' => 'TENANT_NOT_FOUND', 'message' => 'Boutique introuvable'], json_decode($response->getContent(), true), $input);
        }
        // Un échec n'est pas mis en cache : le code devient valide dès qu'un site le porte
        $manager = static::getContainer()->get(TenantConnectionManager::class);
        $pdo = $manager->getPdoMaster();
        $site = 'mvtest-short-' . bin2hex(random_bytes(2));
        $this->assertSame(404, $this->check('ZZZ-ZZ9')->getStatusCode());
        $pdo->prepare('INSERT INTO tenants (code, name, dbname, short_code) VALUES (?, ?, ?, ?)')->execute([$site, 'Site code court', MV_TEST_TENANT_DB, 'ZZZZZ9']);
        try {
            $body = json_decode($this->check('zzz-zz9')->getContent(), true);
            $this->assertSame(['code' => $site, 'name' => 'Site code court', 'host' => $site . '.' . $this->baseDomain(), 'shortCode' => 'ZZZZZ9'], $body['tenant'] ?? null, json_encode($body));
        } finally {
            $pdo->prepare('DELETE FROM tenants WHERE code = ?')->execute([$site]);
            static::getContainer()->get('cache.app')->invalidateTags(['tenants']);
        }
    }

    public function testShortCodeLookupsAreRateLimitedPerIpButExactLookupsAreNot(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->assertSame(404, $this->check(sprintf('ZZ%04d', $i))->getStatusCode(), "essai $i accepté");
        }
        $blocked = $this->check('MVT001');
        $this->assertSame(429, $blocked->getStatusCode(), '21e essai de code court par la même IP');
        $this->assertSame('TOO_MANY_ATTEMPTS', json_decode($blocked->getContent(), true)['error']);
        $this->assertGreaterThanOrEqual(1, (int) $blocked->headers->get('Retry-After'));
        // La recherche exacte reste sans limite pour cette IP
        $this->assertSame(200, $this->check(MV_TEST_TENANT_CODE)->getStatusCode());
        $this->assertSame(200, $this->check(MV_TEST_TENANT_HOST)->getStatusCode());
        // Une autre IP n'est pas concernée
        $this->ip = '10.200.200.200';
        $this->assertSame(200, $this->check('MVT-001')->getStatusCode());
    }

    private function baseDomain(): string
    {
        return (string) ($_SERVER['FRONTEND_BASE_DOMAIN'] ?? $_ENV['FRONTEND_BASE_DOMAIN'] ?? getenv('FRONTEND_BASE_DOMAIN'));
    }

    private function check(string $host): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/tenant/check-mobile', [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_TENANT_HOST' => $host, 'REMOTE_ADDR' => $this->ip]);

        return $this->client->getResponse();
    }
}
