<?php

namespace App\Tests\Functional\Cache;

use App\Entity\Categories;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Services\FeatureAccess\ModuleAccessService;
use App\Services\TenantCacheService;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantHostVariant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * IMGHOST (contrat validé le 12/11/2026) : la clé du cache d'un site porte le schéma et l'hôte de la requête, normalisé parmi les noms
 * connus du site. Chaque hôte connu relit ses propres adresses ; un hôte inconnu retombe sur la variante canonique, en lecture seule
 * (rien n'est enregistré sous son nom) ; une écriture et delete() effacent toutes les variantes. Renforcement des interrupteurs : un
 * cache des modules sans la clé d'un module est tenu pour périmé.
 */
class CacheHostVariantTest extends WebTestCase
{
    private const CUSTOM = 'https://' . MV_TEST_TENANT_HOST; // domaine propre du site de test
    private const BACKEND = 'https://' . MV_TEST_TENANT_CODE . '.backend-strapi.online'; // <code>.<domaine du backend> = variante canonique
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot(); // même conteneur : la pile de requêtes sert aux essais du service
        static::getContainer()->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
    }

    public function testKnownHostsAreNormalizedAndUnknownHostsFallBackToTheCanonicalVariant(): void
    {
        $hosts = static::getContainer()->get(TenantHostVariant::class);
        $this->assertNull($hosts->current(), 'hors requête : aucune variante');
        $variant = function (string $url) use ($hosts) {
            return $this->within($url, fn () => $hosts->current());
        };
        $this->assertSame([self::CUSTOM, true], $variant(self::CUSTOM . '/api/x'), 'domaine propre');
        $this->assertSame([self::CUSTOM, true], $variant('https://MVTEST.test:443/api/x'), 'minuscules, port par défaut retiré');
        $this->assertSame(['https://www.' . MV_TEST_TENANT_HOST, true], $variant('https://www.' . MV_TEST_TENANT_HOST . '/'), 'www. du domaine propre');
        $this->assertSame([self::BACKEND, true], $variant(self::BACKEND . '/'), '<code>.<domaine du backend>');
        $this->assertSame(['https://' . MV_TEST_TENANT_CODE . '.v2.backend-strapi.online', true], $variant('https://' . MV_TEST_TENANT_CODE . '.v2.backend-strapi.online/'), 'sous-domaine du backend');
        $this->assertSame(['https://backend-strapi.online', true], $variant('https://backend-strapi.online/'), 'hôte partagé du backend (site donné par X-Tenant-Host)');
        $this->assertSame(['http://' . MV_TEST_TENANT_HOST, true], $variant('http://' . MV_TEST_TENANT_HOST . '/'), 'le schéma fait partie de la variante');
        $this->assertSame(['http://localhost', true], $variant('http://localhost/'));
        foreach (['https://evil.example/', 'https://autre.backend-strapi.online/', 'https://172.18.0.5/', 'https://' . MV_TEST_TENANT_CODE . '.evil.example/', 'https://' . MV_TEST_TENANT_CODE . '/'] as $url) {
            $this->assertSame([self::BACKEND, false], $variant($url), "hôte inconnu : $url");
        }
    }

    public function testEachKnownHostReadsItsOwnEntryAndWritesAndDeleteClearEveryVariant(): void
    {
        $cache = static::getContainer()->get(TenantCacheService::class);
        $key = 'imghost_' . bin2hex(random_bytes(4));
        $calls = 0;
        $read = function (string $url, array $tags = ['entreprise']) use ($cache, $key, &$calls) {
            return $this->within($url, function () use ($cache, $key, &$calls, $url, $tags) {
                return $cache->get($key, function () use (&$calls, $url) {
                    ++$calls;

                    return 'calculé pour ' . parse_url($url, PHP_URL_HOST);
                }, 600, $tags);
            });
        };
        $a = self::CUSTOM . '/';
        $b = self::BACKEND . '/';
        $this->assertSame(['calculé pour ' . MV_TEST_TENANT_HOST, 'calculé pour ' . MV_TEST_TENANT_CODE . '.backend-strapi.online'], [$read($a), $read($b)], 'chaque hôte a sa valeur');
        $this->assertSame(['calculé pour ' . MV_TEST_TENANT_HOST, 'calculé pour ' . MV_TEST_TENANT_CODE . '.backend-strapi.online', 2], [$read($a), $read($b), $calls], 'relues du cache, sans recalcul');

        // Hôtes inconnus : la variante canonique, sans rien enregistrer ; 50 hôtes différents ne créent aucune entrée
        for ($i = 0; $i < 50; ++$i) {
            $this->assertSame('calculé pour ' . MV_TEST_TENANT_CODE . '.backend-strapi.online', $read("https://inconnu-$i.example/"), 'adresses de la variante canonique, jamais celles de l\'hôte inconnu');
        }
        $this->assertSame(2, $calls, 'aucun calcul : la variante canonique était en cache');
        $cache->delete($key);
        $this->assertSame(['calculé pour ' . MV_TEST_TENANT_HOST, 'calculé pour ' . MV_TEST_TENANT_CODE . '.backend-strapi.online', 4], [$read($a), $read($b), $calls], 'delete() efface les deux variantes');

        // Variante canonique vide : l'hôte inconnu calcule pour lui seul, à chaque fois, sans rien enregistrer
        $cache->delete($key);
        $before = $calls;
        $this->assertSame('calculé pour inconnu-a.example', $read('https://inconnu-a.example/'));
        $this->assertSame('calculé pour inconnu-a.example', $read('https://inconnu-a.example/'));
        $this->assertSame($before + 2, $calls, 'jamais enregistré');
        $this->assertSame('calculé pour ' . MV_TEST_TENANT_CODE . '.backend-strapi.online', $read($b), 'la variante canonique n\'a pas reçu la valeur de l\'hôte inconnu');
        $this->assertSame('calculé pour ' . MV_TEST_TENANT_HOST, $read($a));

        // Une écriture (étiquette du composant) invalide toutes les variantes
        $calls = 0;
        $cache->invalidateTags(['entreprise']);
        $read($a);
        $read($b);
        $this->assertSame(2, $calls, 'les deux variantes recalculées après l\'invalidation');
        $cache->delete($key);
    }

    public function testACachedPublicReadServesEachHostItsOwnAddresses(): void
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $slug = 'imghost-' . bin2hex(random_bytes(3));
        $category = (new Categories())->setName('Imghost ' . $slug);
        $product = (new Product())->setName('Produit imghost')->setSlug($slug)->setPrice(1500)->setIsWeb(true)->setSaleEnabled(true)->setImage('photo-imghost.jpg');
        $product->addVariant((new ProductVariant())->setStockQuantity(2)->setPrice(1500));
        $product->addCategory($category);
        $em->persist($category);
        $em->persist($product);
        $em->flush();
        $path = '/api/products/by-category?categories=' . urlencode(json_encode([$category->getId()]));
        $image = function (string $base) use ($path, $product): string {
            $this->client->request('GET', $base . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json']);
            $this->assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
            $found = null;
            $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
            array_walk_recursive($decoded, function ($v) use (&$found) {
                if (is_string($v) && str_ends_with($v, 'photo-imghost.jpg')) {
                    $found ??= $v;
                }
            });
            $this->assertNotNull($found, 'image du produit ' . $product->getId());

            return $found;
        };
        $a = $image(self::CUSTOM);
        $b = $image(self::BACKEND);
        $this->assertStringStartsWith(self::CUSTOM . '/', $a);
        $this->assertStringStartsWith(self::BACKEND . '/', $b);
        $this->assertSame([$a, $b], [$image(self::CUSTOM), $image(self::BACKEND)], 'chaque hôte relit ses adresses (avant IMGHOST : celles du premier appelant)');
        $this->assertSame($b, $image('https://inconnu.example'), 'hôte inconnu : adresses de la variante canonique');
    }

    public function testModuleFlagsCachedWithoutAModuleKeyAreStaleAndReadAgain(): void
    {
        $cache = static::getContainer()->get(TenantCacheService::class);
        $modules = static::getContainer()->get(ModuleAccessService::class);
        $db = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->getConnection();
        $db->executeStatement('UPDATE entreprise SET is_support_active = false');
        try {
            $this->within(self::CUSTOM . '/', function () use ($cache, $modules) {
                $cache->delete('module_flags');
                // Cache rempli par un code d'avant le module Assistance : pas de clé « support »
                $stale = array_fill_keys(array_diff(array_keys(ModuleAccessService::COLUMNS), [ModuleAccessService::SUPPORT]), true);
                $this->assertSame($stale, $cache->get('module_flags', fn () => $stale, 600, ['entreprise', 'entreprise.list']));
                $this->assertFalse($modules->isEnabled(ModuleAccessService::SUPPORT), 'module éteint en base : vu éteint, sans invalidation manuelle');
                $this->assertSame(array_keys(ModuleAccessService::COLUMNS), array_keys($modules->flags()), 'cache relu avec tous les modules');
                $this->assertSame(array_keys(ModuleAccessService::COLUMNS), array_keys($cache->get('module_flags', fn () => $this->fail('le cache à jour doit être relu'), 600, ['entreprise'])));
            });
        } finally {
            $cache->delete('module_flags');
        }
    }

    /** Exécute $do comme pendant une requête à $url (pile de requêtes du conteneur) */
    private function within(string $url, callable $do): mixed
    {
        $stack = static::getContainer()->get('request_stack');
        $stack->push(Request::create($url));
        try {
            return $do();
        } finally {
            $stack->pop();
        }
    }
}
