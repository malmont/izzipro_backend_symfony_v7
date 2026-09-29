<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\AccessMapInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsCommand(
    name: 'app:docs:endpoints',
    description: 'Index des endpoints (Markdown sur la sortie standard) : routes par module, rôle exigé par access_control'
)]
class GenerateEndpointsDocCommand extends Command
{
    /** Le conteneur tourne en www-data et ne peut pas écrire dans docs/ : redirection depuis l'hôte */
    public const REGENERATE = 'docker exec -w /var/www symfony_app_v2 php bin/console app:docs:endpoints > docs/endpoints.md';

    /** Module par préfixe de chemin (premier préfixe trouvé), avant le repli par espace de noms */
    private const PATH_MODULES = [
        '/api/landingpage' => 'Landing Page',
        '/api/components-config' => 'Landing Page',
        '/api/reservations' => 'Réservations',
        '/booking' => 'Réservations',
        '/api/booking' => 'Réservations',
        '/admin' => 'Administration (EasyAdmin)',
        '/media/' => 'Fichiers et médias',
        '/uploads/' => 'Fichiers et médias',
        '/assets/uploads/' => 'Fichiers et médias',
        '/bucket-simulator/' => 'Fichiers et médias',
        '/api/shared-media' => 'Fichiers et médias',
        '/shared-media' => 'Fichiers et médias',
        '/api/login' => 'Authentification et comptes',
        '/api/logout' => 'Authentification et comptes',
        '/api/token/' => 'Authentification et comptes',
        '/api/validate-token' => 'Authentification et comptes',
        '/api/register' => 'Authentification et comptes',
        '/api/password-reset' => 'Authentification et comptes',
        '/api/otp' => 'Authentification et comptes',
        '/api/resend-verification' => 'Authentification et comptes',
        '/verify' => 'Authentification et comptes',
        '/api/tenant' => 'Tenants',
        '/setup' => 'Tenants',
        '/api/health' => 'Tenants',
    ];
    /** Contenus affichés par les sections des landing pages (données choisies dans « Données à afficher ») */
    private const CONTENT_PREFIXES = ['/api/bannieres', '/bannieres', '/api/baniere-statiques', '/baniere-statiques', '/api/presentations', '/presentations',
        '/api/presentation-groups', '/api/videos', '/api/embeds', '/api/multiliens', '/multiliens', '/api/recherches', '/api/emplois', '/emplois',
        '/api/candidatures', '/api/marques', '/api/categories-marque', '/categories-marque', '/api/service-offers', '/service-offers', '/api/team',
        '/api/contacts', '/api/contact', '/api/newsletter', '/api/financement'];
    private const MODULE_ORDER = ['Landing Page', 'Contenus des sites', 'Mémoires Vivantes', 'Boussole ESG', 'Réservations', 'Boutique et commun',
        'Authentification et comptes', 'Tenants', 'Fichiers et médias', 'Administration (EasyAdmin)', 'API Platform'];

    public function __construct(
        private readonly RouterInterface $router,
        #[Autowire(service: 'security.access_map')]
        private readonly AccessMapInterface $accessMap
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $modules = [];
        foreach ($this->router->getRouteCollection()->all() as $name => $route) {
            if ($this->ignored($name, $route)) {
                continue;
            }
            $modules[$this->module($route)][] = [
                implode(', ', $route->getMethods() ?: ['ANY']),
                '`' . $route->getPath() . '`',
                $this->access($route),
                $this->controller($route),
            ];
        }

        $lines = [
            '# Index des endpoints du backend',
            '',
            'Généré (ne pas modifier à la main) ; après tout ajout ou changement de route, régénérer depuis la racine du dépôt :',
            '`' . self::REGENERATE . '`',
            'Contexte : `docs/architecture.md` ; détail de chaque module : sa fiche.',
            '',
            '- **Accès** : rôle exigé par la première règle `access_control` de `config/packages/security.yaml` qui s\'applique',
            '  (`PUBLIC_ACCESS` = sans connexion ; `aucune règle` = pas de contrôle à ce niveau). Les contrôleurs ajoutent parfois',
            '  leurs propres vérifications : `#[IsGranted]` est signalé, les autres (voters, contrôles dans le code) sont dans les fiches.',
            '- Toutes les routes sont résolues pour le tenant de la requête (en-tête `X-Tenant-Host`, voir `docs/architecture.md`).',
            '',
        ];
        $order = array_flip(self::MODULE_ORDER);
        uksort($modules, fn ($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99) ?: strcmp($a, $b));
        foreach ($modules as $module => $rows) {
            usort($rows, fn ($a, $b) => [$a[1], $a[0]] <=> [$b[1], $b[0]]);
            $lines[] = sprintf('## %s (%d)', $module, count($rows));
            $lines[] = '';
            $lines[] = '| Méthodes | Route | Accès | Contrôleur |';
            $lines[] = '|---|---|---|---|';
            foreach ($rows as $row) {
                $lines[] = '| ' . implode(' | ', array_map(fn ($cell) => str_replace('|', '\\|', $cell), $row)) . ' |';
            }
            $lines[] = '';
        }
        $output->write(implode("\n", $lines));

        return Command::SUCCESS;
    }

    /** Outils de développement et routes internes d'API Platform (documentation, contexte JSON-LD) */
    private function ignored(string $name, Route $route): bool
    {
        return (bool) preg_match('/^(_profiler|_wdt|_preview_error|api_doc|api_entrypoint|api_jsonld_context|api_genid|api_validation_errors)/', $name)
            || str_starts_with($route->getPath(), '/_error')
            || str_contains((string) $route->getDefault('_controller'), 'NotExposedAction');
    }

    private function module(Route $route): string
    {
        $controller = (string) $route->getDefault('_controller');
        $path = $route->getPath();
        if (str_starts_with($controller, 'App\\MemoiresVivantes\\')) {
            return 'Mémoires Vivantes';
        }
        if (str_starts_with($controller, 'App\\ESG\\')) {
            return 'Boussole ESG';
        }
        if (str_starts_with($controller, 'api_platform.')) {
            return 'API Platform';
        }
        foreach (self::PATH_MODULES as $prefix => $module) {
            if (str_starts_with($path, $prefix)) {
                return $module;
            }
        }
        foreach (self::CONTENT_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/') || str_starts_with($path, $prefix . '.')) {
                return 'Contenus des sites';
            }
        }

        return 'Boutique et commun';
    }

    /** Rôle exigé par access_control, méthode par méthode (les paramètres de route sont remplacés par des exemples) */
    private function access(Route $route): string
    {
        $path = preg_replace_callback('/\{(\w+)[^}]*\}/', function (array $m) use ($route) {
            $requirement = $route->getRequirement($m[1]);

            return $requirement !== null && preg_match('#^(' . $requirement . ')$#', '1') ? '1' : 'x';
        }, $route->getPath());

        $byMethod = [];
        foreach ($route->getMethods() ?: ['GET'] as $method) {
            [$attributes] = $this->accessMap->getPatterns(Request::create($path, $method));
            $byMethod[$method] = $attributes ? implode(' ou ', array_map('strval', $attributes)) : 'aucune règle';
        }
        $access = count(array_unique($byMethod)) === 1
            ? reset($byMethod)
            : implode(' ; ', array_map(fn ($m, $a) => "$m : $a", array_keys($byMethod), $byMethod));

        $granted = $this->isGranted($route);

        return $access . ($granted ? ' + `#[IsGranted(' . $granted . ')]`' : '');
    }

    private function isGranted(Route $route): ?string
    {
        $controller = (string) $route->getDefault('_controller');
        if (!str_contains($controller, '::')) {
            return null;
        }
        [$class, $method] = explode('::', $controller, 2);
        if (!class_exists($class) || !method_exists($class, $method)) {
            return null;
        }
        $reflection = new \ReflectionMethod($class, $method);
        $attributes = [...$reflection->getAttributes(IsGranted::class), ...$reflection->getDeclaringClass()->getAttributes(IsGranted::class)];

        return $attributes ? implode(', ', array_map(fn ($a) => (string) ($a->getArguments()[0] ?? $a->getArguments()['attribute'] ?? '?'), $attributes)) : null;
    }

    private function controller(Route $route): string
    {
        $controller = (string) $route->getDefault('_controller');
        if (str_starts_with($controller, 'api_platform.')) {
            return 'API Platform (' . ($route->getDefault('_api_resource_class') ? substr(strrchr('\\' . $route->getDefault('_api_resource_class'), '\\'), 1) : '?') . ')';
        }
        if (!str_contains($controller, '::')) {
            return '`' . $controller . '`';
        }
        [$class, $method] = explode('::', $controller, 2);

        return '`' . str_replace('App\\', '', $class) . '::' . $method . '`';
    }
}
