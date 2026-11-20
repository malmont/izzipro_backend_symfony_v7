<?php

namespace App;

use App\DependencyInjection\FixedCacheNamespacePass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        // Lot CACHE-ETAT : préfixe fixe des pools d'état, posé avant CachePoolPass (priorité 32)
        $container->addCompilerPass(new FixedCacheNamespacePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 64);
    }
}
