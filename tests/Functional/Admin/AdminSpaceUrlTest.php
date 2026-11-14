<?php

namespace App\Tests\Functional\Admin;

use App\Entity\Entreprise;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Gestion\Services\Console\ConsoleScreens;
use App\Services\Site\AdminSpaceLinks;
use App\Services\Site\AdminSpaceUrl;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot LIEN (14/11/2026, `docs/liens-espace-administration.md`) : le lien d'un courriel du serveur vers un écran de l'espace
 * d'administration du web. Une seule forme, `<adresse>/admin/ouvrir?screen=<code>&<paramètres>` ; l'adresse est le réglage du site,
 * sinon son domaine principal ; un code hors de la liste de référence ou un paramètre obligatoire manquant ne donne aucun lien ; un
 * paramètre inconnu ou mal formé est écarté ; une adresse mal formée n'est jamais gardée.
 */
class AdminSpaceUrlTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->db()->executeStatement('UPDATE entreprise SET admin_space_url = NULL');
    }

    protected function tearDown(): void
    {
        $this->db()->executeStatement('UPDATE entreprise SET admin_space_url = NULL');
        parent::tearDown();
    }

    public function testAnAddressIsKeptOnlyWhenItIsAPlainHttpsAddress(): void
    {
        $cases = [
            'https://www.exemple.test' => 'https://www.exemple.test',
            ' https://WWW.Exemple.test/ ' => 'https://www.exemple.test',
            'https://www.exemple.test/espace/' => 'https://www.exemple.test/espace',
            'https://www.exemple.test:8443/espace' => 'https://www.exemple.test:8443/espace',
            'http://www.exemple.test' => null,                       // pas en https
            'https://gerant:secret@www.exemple.test' => null,        // identifiants dans l'adresse
            'https://www.exemple.test/?retour=1' => null,            // paramètres
            'https://www.exemple.test/#ancre' => null,               // ancre
            'https://exemple' => null,                               // pas un domaine
            'https://www.exemple.test/un chemin' => null,            // espace
            'www.exemple.test' => null,
            'javascript:alert(1)' => null,
            '' => null,
            'https://' . str_repeat('a', 250) . '.test' => null,     // trop longue
        ];
        foreach ($cases as $given => $expected) {
            $this->assertSame($expected, AdminSpaceUrl::normalize($given), "« $given »");
            // la fiche du site ne garde jamais une adresse refusée
            $this->assertSame($expected, (new Entreprise())->setAdminSpaceUrl($given)->getAdminSpaceUrl(), "fiche du site : « $given »");
        }
        $this->assertNull(AdminSpaceUrl::normalize(null));
    }

    public function testTheLinkStartsFromTheSiteSettingOrFromItsMainDomain(): void
    {
        $url = $this->service();
        // Sans réglage : le domaine principal du site, en https
        $this->assertNull($url->explicit());
        $this->assertSame('https://' . MV_TEST_TENANT_HOST, $url->base());
        $this->assertSame('https://' . MV_TEST_TENANT_HOST . '/admin/ouvrir?screen=pertes.home', $url->to('pertes.home'));
        // Avec le réglage : lui, sans barre finale
        $this->db()->executeStatement("UPDATE entreprise SET admin_space_url = 'https://admin.exemple.test/espace'");
        $this->assertSame('https://admin.exemple.test/espace', $url->base());
        $this->assertSame('https://admin.exemple.test/espace/admin/ouvrir?screen=pertes.constats&isNew=1', $url->to('pertes.constats', ['isNew' => true]));
        // Une valeur mal formée arrivée en base par un autre chemin est ignorée : le domaine du site sert
        $this->db()->executeStatement("UPDATE entreprise SET admin_space_url = 'http://admin.exemple.test'");
        $this->assertNull($url->explicit());
        $this->assertSame('https://' . MV_TEST_TENANT_HOST, $url->base());
    }

    public function testOnlyListedScreensAndWellFormedParametersGoIntoALink(): void
    {
        $url = $this->service();
        $base = 'https://' . MV_TEST_TENANT_HOST . '/admin/ouvrir?screen=';
        // Paramètres rédigés : drapeau, plusieurs valeurs dans l'ordre de la liste, une valeur ; l'inconnu et le mal formé sont écartés
        $this->assertSame($base . 'pertes.constats&isNew=1&status=open%2Cwatch&rule=CASH_DISCREPANCY',
            $url->to('pertes.constats', ['isNew' => true, 'status' => ['watch', 'open', 'inconnu'], 'rule' => 'CASH_DISCREPANCY', 'autre' => 'x']));
        $this->assertSame($base . 'pertes.constats&status=confirmed', $url->to('pertes.constats', ['isNew' => false, 'status' => 'confirmed,nimporte', 'rule' => 'cash_discrepancy']));
        $this->assertSame($base . 'pertes.constats', $url->to('pertes.constats', ['status' => [], 'rule' => ['CASH_DISCREPANCY']]));
        // Paramètre obligatoire : sans lui, ou mal formé, aucun lien
        $this->assertSame($base . 'pertes.constat&id=12', $url->to('pertes.constat', ['id' => 12]));
        $this->assertSame($base . 'pertes.constat&id=12', $url->to('pertes.constat', ['id' => '12']));
        foreach ([[], ['id' => 0], ['id' => -3], ['id' => 'abc'], ['id' => '12 OR 1=1'], ['id' => 1.5], ['id' => 99999999999]] as $bad) {
            $this->assertNull($url->to('pertes.constat', $bad), json_encode($bad));
        }
        // Assistance et rapports
        $this->assertSame($base . 'assistance&status=open&overdue=1', $url->to('assistance', ['status' => 'open', 'overdue' => 1, 'awaiting' => 0]));
        $this->assertSame($base . 'assistance.demande&id=7', $url->to('assistance.demande', ['id' => 7]));
        $this->assertSame($base . 'rapports', $url->to('rapports'));
        $this->assertSame($base . 'rapports&id=45', $url->to('rapports', ['id' => 45]));
        // Un code hors de la liste de référence : aucun lien, même s'il existe ailleurs dans l'application
        foreach (['tresorerie', 'gestion.home', 'pertes', 'pertes.constats/../x', '', 'PERTES.HOME'] as $screen) {
            $this->assertNull($url->to($screen), "« $screen »");
        }
        // Chaque code de la liste donne un lien dès que ses paramètres obligatoires sont là
        foreach (AdminSpaceLinks::LINKS as $screen => $link) {
            $this->assertSame($base . $screen . (($link['required'] ?? []) === [] ? '' : '&id=1'), $url->to($screen, array_fill_keys($link['required'] ?? [], 1)), $screen);
        }
        // Seule exception à l'entrée unique (lot SUPPORT-WEB) : le choix du mot de passe d'un compte invité. Le jeton à usage unique est
        // dans le FRAGMENT de l'adresse, jamais dans ses paramètres ; 64 caractères hexadécimaux, rien d'autre ; ce n'est pas un code d'écran
        $token = str_repeat('ab', 32);
        $this->assertSame('https://' . MV_TEST_TENANT_HOST . '/admin/activer#token=' . $token, $url->activation($token));
        $this->assertStringNotContainsString('?', (string) $url->activation($token));
        foreach (['court', str_repeat('g', 64), $token . 'a', strtoupper($token), ''] as $bad) {
            $this->assertNull($url->activation($bad), "« $bad »");
        }
        $this->assertNull($url->to('compte.activer', ['token' => $token]));
        $this->assertArrayNotHasKey('compte.activer', AdminSpaceLinks::LINKS);
    }

    public function testAScreenAlsoKnownToTheConsoleKeepsTheSameParameterShape(): void
    {
        foreach (AdminSpaceLinks::LINKS as $screen => $link) {
            $console = ConsoleScreens::SCREENS[$screen]['params'] ?? null;
            if ($console === null) {
                continue;
            }
            foreach ($link['params'] ?? [] as $name => $shape) {
                if (isset($console[$name]) && is_string($console[$name]) && is_string($shape)) {
                    $this->assertSame($console[$name], $shape, "$screen.$name : même forme dans le registre des écrans de la console");
                }
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testTheRegistryGivesTheSettingAndTheAddressActuallyUsed(): void
    {
        $token = $this->adminToken();
        $read = function () use ($token): array {
            $this->client->request('GET', '/api/site/features', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
            $this->assertSame(200, $this->client->getResponse()->getStatusCode(), substr((string) $this->client->getResponse()->getContent(), 0, 300));
            foreach (json_decode((string) $this->client->getResponse()->getContent(), true)['features'] as $feature) {
                if ($feature['key'] === 'site.admin_space_url') {
                    return $feature;
                }
            }
            $this->fail('site.admin_space_url absent du registre');
        };
        $f = $read();
        $this->assertSame(['url', 'site', null, 'https://' . MV_TEST_TENANT_HOST, 'ready', 'entreprise'], [$f['type'], $f['scope'], $f['value'], $f['effective'], $f['status'], $f['href']]);
        $this->db()->executeStatement("UPDATE entreprise SET admin_space_url = 'https://admin.exemple.test'");
        static::getContainer()->get(CacheInvalidationSubscriber::class)->invalidateWrittenTenants();
        $f = $read();
        $this->assertSame(['https://admin.exemple.test', 'https://admin.exemple.test'], [$f['value'], $f['effective']]);
    }

    private function service(): AdminSpaceUrl
    {
        $this->provider();

        return static::getContainer()->get(AdminSpaceUrl::class);
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        return $this->provider()->getEntityManager()->getConnection();
    }

    private function provider(): TenantEntityManagerProvider
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider;
    }

    private function adminToken(): string
    {
        $email = 'lien-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->provider()->getEntityManager();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Lien')->setLastname('Test')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        static::getContainer()->get(CacheInvalidationSubscriber::class)->invalidateWrittenTenants();

        return $token;
    }
}
