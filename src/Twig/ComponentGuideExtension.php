<?php

namespace App\Twig;

use App\Services\Admin\ComponentGuideCatalog;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** `admin_component_guide(fqcn)` : guide d'un écran de composant (08/11/2026), ou null. */
class ComponentGuideExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('admin_component_guide', [ComponentGuideCatalog::class, 'for'])];
    }
}
