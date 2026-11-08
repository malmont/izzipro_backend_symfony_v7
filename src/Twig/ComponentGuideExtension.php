<?php

namespace App\Twig;

use App\Services\Admin\ComponentGuideCatalog;
use App\Services\SiteContentService\SiteDemoContentService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `admin_component_guide(fqcn)` : guide d'un écran de composant (08/11/2026), ou null.
 * `admin_demo_content(fqcn)` : contenu d'exemple encore présent sur cet écran (lot 3) : { resource, ids, count, deleteUrl }, ou null.
 */
class ComponentGuideExtension extends AbstractExtension
{
    public function __construct(private readonly SiteDemoContentService $demo, private readonly UrlGeneratorInterface $router)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('admin_component_guide', [ComponentGuideCatalog::class, 'for']),
            new TwigFunction('admin_demo_content', [$this, 'demoContent']),
        ];
    }

    /** @return array{resource: string, ids: list<int>, count: int, deleteUrl: string}|null */
    public function demoContent(?string $controllerFqcn): ?array
    {
        $guide = ComponentGuideCatalog::for($controllerFqcn);
        if ($guide === null || !isset($guide['resource'])) {
            return null;
        }
        try {
            $ids = array_map(fn ($i) => (int) $i['id'], $this->demo->list('fr', [$guide['resource']]));
        } catch (\Throwable) {
            return null;
        }

        return ['resource' => $guide['resource'], 'ids' => $ids, 'count' => count($ids), 'deleteUrl' => $this->router->generate('admin_demo_content_delete', ['resource' => $guide['resource']])];
    }
}
