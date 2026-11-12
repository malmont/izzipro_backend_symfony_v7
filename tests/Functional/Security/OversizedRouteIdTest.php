<?php

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\EventListener\OversizedRouteIdListener;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Lot ROUTEID (12/11/2026) : un identifiant entier trop grand DANS L'ADRESSE d'une route (paramètre `id` ou finissant par `Id`, tout en
 * chiffres, au-delà de 2 147 483 647) répond 404 avant le contrôleur. Avant : la recherche échouait en base (entier hors limites), 500,
 * sur toute la plateforme, routes publiques comprises. Les identifiants non numériques (hexadécimaux, codes), les autres paramètres
 * (code-barres, jeton) et le contrôle d'accès ne changent pas.
 */
class OversizedRouteIdTest extends WebTestCase
{
    private const HUGE = '99999999999';
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Le cache système survit d'un passage de tests à l'autre : la liste des exceptions est recalculée avec le code présent
        static::getContainer()->get('cache.system')->deleteItem(OversizedRouteIdListener::CACHE_KEY);
    }

    /** @return iterable<string, array{string, string, bool}> méthode, adresse, connexion administrateur */
    public static function routes(): iterable
    {
        yield 'gestion : frais de port d\'une commande' => ['GET', '/api/commandes/' . self::HUGE . '/frais-de-port', true];
        yield 'gestion : commandes d\'une période' => ['GET', '/api/collections/' . self::HUGE . '/commandes', true];
        yield 'gestion : document lu par IA' => ['GET', '/api/gestion/documents/' . self::HUGE, true];
        yield 'gestion : aperçu d\'une facture' => ['POST', '/api/gestion/documents/' . self::HUGE . '/apercu', true];
        yield 'gestion : ligne d\'achat (second paramètre)' => ['PATCH', '/api/commandes/1/lignes/' . self::HUGE, true];
        yield 'boutique publique : fiche produit (API Platform)' => ['GET', '/api/products/' . self::HUGE, false];
        yield 'boutique publique : fiche produit' => ['GET', '/api/productsid/' . self::HUGE, false];
        yield 'boutique publique : variantes' => ['GET', '/api/products/' . self::HUGE . '/variants', false];
        yield 'landing publique : présentation' => ['GET', '/api/presentations/' . self::HUGE, false];
        yield 'caisse : rapport Z' => ['GET', '/api/caisse/report/z/' . self::HUGE, true];
        yield 'assistance : demande' => ['GET', '/api/support/tickets/' . self::HUGE, true];
        yield 'suppression d\'un article' => ['DELETE', '/api/products/' . self::HUGE, true];
        yield 'chaîne de chiffres très longue' => ['GET', '/api/commandes/' . str_repeat('9', 40) . '/frais-de-port', true];
        yield 'juste au-dessus de la borne' => ['GET', '/api/commandes/2147483648/frais-de-port', true];
    }

    /** @dataProvider routes */
    public function testAnOversizedIdInTheAddressIsNotFound(string $method, string $path, bool $admin): void
    {
        $this->client->request($method, $path, [], [], $this->headers($admin ? $this->token(['ROLE_ADMIN']) : null), in_array($method, ['POST', 'PATCH'], true) ? '{}' : null);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode(), "$method $path : " . substr((string) $this->client->getResponse()->getContent(), 0, 200));
    }

    public function testAccessControlAndRegularIdsAreUnchanged(): void
    {
        // Sans connexion : toujours 401, l'identifiant trop grand ne passe pas devant le contrôle d'accès
        $this->client->request('GET', '/api/commandes/' . self::HUGE . '/frais-de-port', [], [], $this->headers(null));
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        // À la borne et en dessous : la route répond comme avant (commande inconnue : 404 de la recherche)
        $token = $this->token(['ROLE_ADMIN']);
        foreach (['2147483647', '999999', '0000000000000999999'] as $id) {
            $this->client->request('GET', "/api/commandes/$id/frais-de-port", [], [], $this->headers($token));
            $this->assertSame(404, $this->client->getResponse()->getStatusCode(), $id);
        }
        $this->client->request('GET', '/api/collections', [], [], $this->headers($token));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testOnlyNumericIdParametersAreChecked(): void
    {
        $listener = static::getContainer()->get(OversizedRouteIdListener::class);
        $refused = function (string $route, array $params) use ($listener): bool {
            $request = Request::create('/');
            $request->attributes->set('_route', $route);
            $request->attributes->set('_route_params', $params);
            try {
                $listener(new RequestEvent(static::getContainer()->get('kernel'), $request, HttpKernelInterface::MAIN_REQUEST));
            } catch (NotFoundHttpException) {
                return true;
            }

            return false;
        };
        $this->assertTrue($refused('get_frais_de_port', ['id' => self::HUGE]), 'id sans exigence');
        $this->assertTrue($refused('gestion_documents_preview', ['id' => self::HUGE]), 'id à exigence numérique');
        $this->assertTrue($refused('commande_lines_update', ['id' => '1', 'lineId' => self::HUGE]), 'paramètre finissant par Id');
        $this->assertTrue($refused('get_frais_de_port', ['id' => '2147483648']));
        $this->assertFalse($refused('get_frais_de_port', ['id' => '2147483647']), 'la borne est un identifiant possible');
        $this->assertFalse($refused('get_frais_de_port', ['id' => '00000000000000000042']), 'zéros de tête');
        $this->assertFalse($refused('get_frais_de_port', ['id' => 'abc' . self::HUGE]), 'pas tout en chiffres');
        $this->assertFalse($refused('api_site_assistant_confirm', ['id' => '1234567890123456']), 'identifiant hexadécimal de 16 caractères, ici tout en chiffres');
        $this->assertFalse($refused('gestion_accueil_vignettes_ecarter', ['id' => '12345678901']), 'identifiant en code');
        $this->assertFalse($refused('api_products_ai_bulk_status', ['jobId' => '12345678901']), 'identifiant de tâche à exigence non numérique');
        $this->assertFalse($refused('pos_public_receipt', ['token' => self::HUGE . self::HUGE]), 'jeton : autre paramètre');
        $this->assertFalse($refused('get_frais_de_port', ['barcode' => '4006381333931', 'identifiant' => self::HUGE, 'valid' => self::HUGE]), 'code-barres et paramètres qui ne sont ni id ni …Id');
        // La liste des exceptions est gardée dans le cache système : les 7 paramètres à exigence non numérique d'aujourd'hui, pas plus
        $cached = static::getContainer()->get('cache.system')->getItem(OversizedRouteIdListener::CACHE_KEY);
        $this->assertTrue($cached->isHit());
        $this->assertSame(
            ['admin_mon_site_assistant_confirm|id', 'admin_product_ai_bulk_status|jobId', 'admin_site_configurer_apply|jobId', 'admin_site_configurer_composition|jobId', 'api_products_ai_bulk_status|jobId', 'api_site_assistant_confirm|id', 'gestion_accueil_vignettes_ecarter|id'],
            (function (array $keys) { sort($keys); return $keys; })(array_keys($cached->get())),
            'un nouveau paramètre id à exigence non numérique doit être voulu : ajuster cette liste'
        );
        $this->assertFalse($refused('route_inconnue', ['id' => '12']));
        $this->assertTrue($refused('route_inconnue', ['id' => self::HUGE]), 'route non retrouvée : aucune exigence connue, l\'entier trop grand est refusé');
    }

    private function headers(?string $token): array
    {
        return ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'] + ($token !== null ? ['HTTP_AUTHORIZATION' => 'Bearer ' . $token] : []);
    }

    private function token(array $roles): string
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $provider->getEntityManager();
        $email = 'routeid-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Route')->setLastname('Id')->setRoles($roles)->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();

        return static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }
}
