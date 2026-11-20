<?php

namespace App\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Lot CACHE-ETAT (20/11/2026) : espace de noms FIXE des pools qui gardent un ÉTAT (et non un vrai cache).
 *
 * Symfony calcule l'espace de noms d'un pool (le préfixe de ses clés dans Redis) à partir du dossier du projet, de la classe du conteneur
 * et, depuis la 7.x, de la classe de l'adaptateur. À la montée 6.4 → 7.4 (train 123), il a changé : les compteurs des limiteurs, les
 * jetons, les actions en attente et le signal d'arrêt des workers ont été perdus d'un coup, et messenger:stop-workers n'atteignait plus les
 * workers de la version d'avant. Avec l'attribut `namespace` de l'étiquette `cache.pool` (respecté par CachePoolPass), le préfixe ne
 * dépend plus de rien : une montée de version, un autre dossier de projet ou un autre adaptateur gardent les mêmes clés.
 *
 * Passe exécutée AVANT CachePoolPass (priorité 32 dans FrameworkBundle). « Vider Redis » d'EasyAdmin épargne ces préfixes
 * (RedisAdminController::PROTECTED_PREFIXES) ; l'essai CacheStateTest les fige.
 */
final class FixedCacheNamespacePass implements CompilerPassInterface
{
    /** Pool => espace de noms fixe (préfixe des clés : « <espace>: ») */
    public const NAMESPACES = [
        'cache.rate_limiter' => 'iz_rl',
        'cache.state' => 'iz_state',
        'cache.messenger.restart_workers_signal' => 'iz_workers',
    ];

    public function process(ContainerBuilder $container): void
    {
        foreach (self::NAMESPACES as $id => $namespace) {
            if (!$container->hasDefinition($id)) {
                continue;
            }
            $definition = $container->getDefinition($id);
            $tags = $definition->getTag('cache.pool') ?: [[]];
            $tags[0]['namespace'] = $namespace;
            $definition->clearTag('cache.pool');
            foreach ($tags as $attributes) {
                $definition->addTag('cache.pool', $attributes);
            }
        }
    }
}
