<?php

namespace App\Tests\Functional\Screens;

use App\Gestion\Sequences\SequenceCatalog;
use App\Gestion\Services\Console\ConsoleScreens;
use App\Gestion\Services\GestionDayService;
use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\Platform\AdminStepUrlResolver;
use App\Services\Screens\ScreenRegistry;
use App\Services\Site\AdminSpaceLinks;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Lot AG-3a : le registre des écrans range TOUT. Chaque route de l'application et chaque écran d'EasyAdmin appartient à un écran du
 * registre ou à une liste « hors écran » nommée ; une route nouvelle qui n'est pas rangée fait échouer cet essai (AG-3b la fermerait).
 * Le classement lui-même est figé dans `tests/Fixtures/ecrans/classement.json` : tout changement de famille d'une route se lit dans le
 * diff de ce fichier, et se RELIT (une route de vente rangée en construction s'ouvrirait à une agence).
 *
 * Régénérer le fichier après un ajout de route, puis relire son diff (la variable donne le chemin où écrire, dans le conteneur) :
 *   docker exec -w /var/www -e REGENERER_CLASSEMENT=/tmp/classement.json … php vendor/bin/phpunit tests/Functional/Screens/ScreenRegistryTest.php
 *   docker exec … cat /tmp/classement.json > tests/Fixtures/ecrans/classement.json
 */
class ScreenRegistryTest extends KernelTestCase
{
    private const FIXTURE = __DIR__ . '/../../Fixtures/ecrans/classement.json';
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    public function testEveryRouteBelongsToAScreenOrToANamedListAndTheClassificationIsTheReviewedOne(): void
    {
        self::bootKernel();
        $byTarget = [];
        $missing = [];
        $used = [];
        foreach (static::getContainer()->get('router')->getRouteCollection() as $route) {
            $path = $route->getPath();
            if (str_starts_with($path, '/_')) {
                continue; // outils de mise au point du cadre (barre, erreurs) : absents de la production
            }
            $perTarget = [];
            foreach (array_values(array_intersect(self::METHODS, $route->getMethods() ?: self::METHODS)) as $method) {
                $target = ScreenRegistry::classify($method, $path);
                if ($target === null) {
                    $missing[] = "$method $path";
                    continue;
                }
                $perTarget[$target][] = $method;
                $used[self::ruleOf($path)] = true;
            }
            foreach ($perTarget as $target => $methods) {
                $byTarget[$target][] = implode('|', $methods) . ' ' . $path;
            }
        }
        $this->assertSame([], $missing, 'Route(s) rangée(s) nulle part : les ajouter à ScreenRegistry::ROUTES, dans un écran ou une liste hors écran.');

        // toute cible est un écran du registre ou une liste hors écran nommée
        foreach (array_keys($byTarget) as $target) {
            $this->assertTrue(isset(ScreenRegistry::SCREENS[$target]) || isset(ScreenRegistry::OFF_SCREEN[$target]), "Cible inconnue : $target");
        }
        // aucune règle morte : une règle qui ne range plus rien se retire (elle masquerait une faute de frappe)
        $dead = array_values(array_diff(array_keys(ScreenRegistry::ROUTES), array_keys($used)));
        $this->assertSame([], array_map(static fn (int $i): string => ScreenRegistry::ROUTES[$i][0], $dead), 'Règle(s) qui ne range(nt) aucune route.');

        // LE CLASSEMENT RELU : cible => routes
        ksort($byTarget);
        foreach ($byTarget as &$routes) {
            sort($routes);
            $routes = array_values(array_unique($routes));
        }
        unset($routes);
        if (is_string(getenv('REGENERER_CLASSEMENT')) && getenv('REGENERER_CLASSEMENT') !== '') {
            // écrit là où le conteneur a le droit d'écrire ; le fichier se copie ensuite dans tests/Fixtures/ecrans/classement.json
            file_put_contents(getenv('REGENERER_CLASSEMENT'), json_encode($byTarget, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n");
            $this->markTestIncomplete('Classement écrit dans ' . getenv('REGENERER_CLASSEMENT') . ' : le copier dans tests/Fixtures/ecrans/classement.json, puis relire son diff.');
        }
        $this->assertFileExists(self::FIXTURE);
        $this->assertSame(json_decode((string) file_get_contents(self::FIXTURE), true), $byTarget,
            'Le classement des routes a changé : régénérer tests/Fixtures/ecrans/classement.json (voir l\'en-tête de cet essai) et RELIRE son diff.');
    }

    public function testEveryEasyAdminScreenIsRangedAndTheRegistryIsConsistent(): void
    {
        // tous les écrans CRUD d'EasyAdmin, où qu'ils soient dans le code et QUEL QUE SOIT LEUR NOM : toute classe concrète qui
        // hérite, de près ou de loin, du contrôleur CRUD d'EasyAdmin (cinq d'entre eux s'appellent « …ListController »)
        $parents = [];
        $abstract = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() === 'php' && preg_match('/^\s*(abstract\s+)?(?:final\s+)?class\s+(\w+)\s+extends\s+\\\\?([\w\\\\]+)/m', (string) file_get_contents($file->getPathname()), $m)) {
                $parents[$m[2]] = substr((string) strrchr('\\' . $m[3], '\\'), 1);
                $abstract[$m[2]] = $m[1] !== '';
            }
        }
        $found = [];
        foreach (array_keys($parents) as $class) {
            for ($parent = $parents[$class], $depth = 0; $parent !== null && $depth < 10; $parent = $parents[$parent] ?? null, ++$depth) {
                if ($parent === 'AbstractCrudController') {
                    if (!$abstract[$class]) {
                        $found[] = $class;
                    }
                    break;
                }
            }
        }
        sort($found);
        foreach (['OrderItemsListController', 'TransactionCaisseListController', 'ProductCrudController', 'BookCrudController'] as $known) {
            $this->assertContains($known, $found, "$known est un écran CRUD d'EasyAdmin");
        }
        $this->assertGreaterThan(100, count($found));
        $this->assertSame([], array_values(array_diff($found, array_keys(ScreenRegistry::ADMIN_CRUDS))), 'Écran(s) d\'EasyAdmin rangé(s) nulle part : les ajouter à ScreenRegistry::ADMIN_CRUDS.');
        $this->assertSame([], array_values(array_diff(array_keys(ScreenRegistry::ADMIN_CRUDS), $found)), 'Écran(s) du registre qui n\'existe(nt) plus.');
        $this->assertSame(count($found), count(array_unique($found)), 'deux écrans d\'EasyAdmin de même nom : le registre les range par nom court');
        foreach (ScreenRegistry::ADMIN_CRUDS as $controller => $screen) {
            $this->assertSame('easyadmin', ScreenRegistry::screen($screen)['surface'] ?? null, "$controller : $screen n'est pas un écran d'EasyAdmin du registre");
            $this->assertSame($screen, ScreenRegistry::adminCrud('App\\Controller\\Admin\\' . $controller));
        }
        // UNE REQUÊTE d'EasyAdmin ne se classe jamais par son chemin : `/admin` sert tous les écrans. Un écran CRUD se classe par son
        // contrôleur, une page ouverte par `routeName` par sa route ; ce que le registre ne connaît pas n'a pas d'écran (fermé)
        $this->assertSame('admin.accueil', ScreenRegistry::adminTarget(null, null));
        $this->assertSame('admin.commandes', ScreenRegistry::adminTarget('App\\Controller\\Admin\\OrderCrudController', null));
        $this->assertSame('admin.commandes', ScreenRegistry::adminTarget('App\\Controller\\Admin\\OrderItemsListController', null));
        $this->assertSame('admin.commandes', ScreenRegistry::adminTarget(null, '/admin/orders/{id}/rembourser', 'POST'), 'une page de remboursement ouverte par routeName reste une page de vente');
        $this->assertSame('admin.catalogue', ScreenRegistry::adminTarget('', '/admin/produits/{id}/photos'));
        $this->assertNull(ScreenRegistry::adminTarget('App\\Controller\\Admin\\InconnuCrudController', '/admin/produits/{id}/photos'), 'un écran inconnu ne se rattrape pas par la route');
        $this->assertNull(ScreenRegistry::adminTarget(null, ''), 'une route inconnue');
        $this->assertNull(ScreenRegistry::adminTarget(null, '/api/route-inconnue'));

        // chaque écran : une famille connue, un module gouverné (ou aucun), des rôles
        foreach (ScreenRegistry::SCREENS as $code => $screen) {
            $this->assertArrayHasKey($screen['family'], ScreenRegistry::FAMILIES, $code);
            $this->assertTrue($screen['module'] === null || isset(FeatureCatalog::GOVERNED[$screen['module']]), "$code : module inconnu");
            $this->assertNotSame([], ScreenRegistry::screen($code)['roles']);
            $this->assertSame(str_starts_with($code, 'admin.'), ScreenRegistry::screen($code)['surface'] === 'easyadmin', "$code : les écrans d'EasyAdmin, et eux seuls, sont préfixés « admin. »");
        }
        // LES CHAMPS SENSIBLES ne se marquent que là où ils ont un sens : sur un écran de construction (ailleurs, l'écran entier est
        // fermé à l'agence), ou sur le site public
        foreach (ScreenRegistry::SENSITIVE as $code => $mark) {
            $this->assertSame(ScreenRegistry::CONSTRUCTION, ScreenRegistry::familyOf($code), "$code : champs sensibles marqués hors de la construction");
            $this->assertNotSame([], array_merge($mark['fields'] ?? [], $mark['closed'] ?? []), $code);
            $this->assertNotSame('', $mark['why']);
        }
        // … et ce dont la réponse entière est sensible est SORTI de la construction
        foreach (['site.assistant', 'site.journal', 'imports.commandes', 'imports.clients', 'admin.assistant'] as $code) {
            $this->assertNotSame(ScreenRegistry::CONSTRUCTION, ScreenRegistry::familyOf($code), $code);
        }
        $this->assertNull(ScreenRegistry::screen('inconnu'));
        $this->assertNull(ScreenRegistry::adminCrud('InconnuCrudController'));

        // LES LISTES DE CODES EXISTANTES continuent de marcher, et tous leurs codes sont au registre
        $codes = array_merge(array_keys(ConsoleScreens::SCREENS), array_keys(AdminSpaceLinks::LINKS), array_values(GestionDayService::SCREENS), SequenceCatalog::APP_SCREENS);
        $this->assertSame([], array_values(array_diff(array_unique($codes), array_keys(ScreenRegistry::SCREENS))), 'Code(s) d\'écran émis par l\'application et absent(s) du registre.');
        // la table des étapes d'EasyAdmin mène à des écrans rangés
        self::bootKernel();
        $routes = static::getContainer()->get('router')->getRouteCollection();
        foreach (AdminStepUrlResolver::SCREENS as $key => $target) {
            if (isset($target['crud'])) {
                $this->assertNotNull(ScreenRegistry::adminCrud($target['crud']), "étape $key");
            } else {
                $route = $routes->get($target['route']);
                $this->assertNotNull($route, "étape $key : route inconnue");
                $this->assertStringStartsWith('admin.', (string) ScreenRegistry::classify('GET', $route->getPath()), "étape $key");
            }
        }
    }

    public function testAMenuShowsOnlyTheScreensOfItsFamiliesModulesAndRoles(): void
    {
        $all = array_fill_keys(array_keys(FeatureCatalog::GOVERNED), true);
        $admin = static fn (string $role): bool => $role === 'ROLE_ADMIN';
        // une famille fermée au mode ne montre aucun de ses écrans, quels que soient les modules et les rôles
        $construction = ScreenRegistry::visible([ScreenRegistry::CONSTRUCTION], $all, $admin);
        $this->assertContains('catalogue', $construction);
        $this->assertContains('admin.catalogue', $construction);
        foreach ($construction as $code) {
            $this->assertSame(ScreenRegistry::CONSTRUCTION, ScreenRegistry::familyOf($code), $code);
        }
        // un module éteint cache ses écrans, et eux seuls
        $withoutShop = ScreenRegistry::visible([ScreenRegistry::CONSTRUCTION], ['module.boutique' => false] + $all, $admin);
        $this->assertNotContains('catalogue', $withoutShop);
        $this->assertContains('pages', $withoutShop);
        $this->assertContains('site.reglages', $withoutShop, 'un écran sans module ne dépend d\'aucun');
        $this->assertNotContains('pages', ScreenRegistry::visible([ScreenRegistry::CONSTRUCTION], ['module.boutique' => true], $admin), 'un module absent de la liste est tenu pour éteint');
        // le rôle : un agent d'assistance ne voit que ses deux écrans, pas les réglages ; le super administrateur seul voit la plateforme
        $support = static fn (string $role): bool => $role === 'ROLE_SUPPORT';
        $this->assertSame(['assistance', 'assistance.demande'], ScreenRegistry::visible(array_keys(ScreenRegistry::FAMILIES), $all, $support));
        $this->assertSame([], ScreenRegistry::visible([ScreenRegistry::PLATEFORME], $all, $admin));
        $this->assertSame(['plateforme.pilotage', 'plateforme.agences', 'plateforme.sites', 'admin.maintenance'], ScreenRegistry::visible([ScreenRegistry::PLATEFORME], $all, static fn (string $role): bool => $role === 'ROLE_SUPER_ADMIN'));
        $this->assertSame([], ScreenRegistry::visible([], $all, static fn (): bool => true));

        // le rangement d'une route : lecture et écriture n'ont pas toujours le même propriétaire ; une route inconnue n'est rangée nulle part
        $this->assertSame(['@public', 'pages.contenus', 'pages.contenus'], [ScreenRegistry::classify('get', '/api/bannieres'), ScreenRegistry::classify('POST', '/api/bannieres'), ScreenRegistry::classify('DELETE', '/api/bannieres/12')]);
        $this->assertSame(['@public', 'catalogue'], [ScreenRegistry::classify('GET', '/api/products/12'), ScreenRegistry::classify('DELETE', '/api/products/12')]);
        $this->assertNull(ScreenRegistry::classify('GET', '/api/route-inconnue'));
        $this->assertNull(ScreenRegistry::classify('PATCH', '/api/ressource-inconnue/12'), 'la route générique de contenu ne range que ses ressources connues');
        // … et CHACUNE de ses ressources, par son vrai chemin, est une écriture de la construction (jamais « site public »)
        foreach (array_keys(\App\Services\LandingContentService\LandingContentSpec::RESOURCES) as $resource) {
            $this->assertSame(ScreenRegistry::CONSTRUCTION, ScreenRegistry::familyOf((string) ScreenRegistry::classify('PATCH', "/api/$resource/12")), "PATCH /api/$resource/12");
        }
        // une liste hors écran ne porte aucune écriture d'administration ni aucune lecture de tout le site : les commandes, les paiements,
        // l'achat d'une étiquette, l'annulation et les rendez-vous sont des écrans de vente ou de clients
        $this->assertSame(['ventes.commandes', 'ventes.commandes', 'ventes.commandes', 'ventes.commandes', 'clients.reservations', '@public', 'clients.reservations', '@public'], [
            ScreenRegistry::classify('GET', '/api/orders'), ScreenRegistry::classify('GET', '/api/payments'), ScreenRegistry::classify('POST', '/api/shipping/buy'),
            ScreenRegistry::classify('POST', '/api/order/cancel/12'), ScreenRegistry::classify('GET', '/api/reservations'), ScreenRegistry::classify('POST', '/api/reservations'),
            ScreenRegistry::classify('POST', '/api/reservations/12/confirm'), ScreenRegistry::classify('GET', '/api/reservations/booked-slots')]);
        $this->assertSame(['catalogue', 'catalogue', '@public', 'admin.paiement', '@technique'], [ScreenRegistry::classify('DELETE', '/api/vehicles/12'), ScreenRegistry::classify('PUT', '/api/vehicles/12.json'),
            ScreenRegistry::classify('GET', '/api/vehicles/carousel'), ScreenRegistry::classify('GET', '/stripe/connect'), ScreenRegistry::classify('POST', '/stripe/webhook')]);
        $this->assertSame('admin.paiement', ScreenRegistry::adminCrud('SquareConfigCrudController'));
        $this->assertNull(ScreenRegistry::familyOf('@public'));
        // ce qu'une agence ne doit jamais ouvrir n'est pas en construction
        foreach (['/api/gestion/articles', '/api/gestion/statistiques', '/api/commandes/12', '/api/fournisseurs', '/api/gestion/pertes/constats', '/api/support/tickets',
            '/api/contacts/12', '/api/form-submissions/export', '/api/memoires/admin/users', '/api/platform/sites', '/admin/orders/12/rembourser', '/admin/clients/12/commandes-avant',
            '/admin/pos/exports', '/admin/stripe/connect', '/admin/users/12/effacer', '/admin/controles',
            '/api/landingpage-audit', '/api/landingpage-audit/12', '/api/site/assistant', '/api/site/imports/archive/orders', '/api/site/imports/12/customers', '/api/site/imports/12/invitations',
            '/admin/mon-site/assistant', '/api/orders', '/api/payments', '/api/reservations', '/stripe/connect'] as $path) {
            $this->assertNotSame(ScreenRegistry::CONSTRUCTION, ScreenRegistry::familyOf((string) ScreenRegistry::classify('GET', $path)), $path);
            $this->assertNotNull(ScreenRegistry::familyOf((string) ScreenRegistry::classify('GET', $path)), $path);
        }
    }

    private static function ruleOf(string $path): ?int
    {
        foreach (ScreenRegistry::ROUTES as $i => [$pattern]) {
            if (preg_match($pattern, $path) === 1) {
                return $i;
            }
        }

        return null;
    }
}
