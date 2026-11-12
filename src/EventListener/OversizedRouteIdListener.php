<?php

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Identifiant entier trop grand dans l'adresse d'une route (lot ROUTEID, 12/11/2026) : 404 avant le contrôleur. Avant, la recherche
 * partait en base avec un entier hors des limites d'une colonne `INT` et échouait (500), sur toute la plateforme, routes publiques
 * comprises (`/api/products/99999999999/variants`, `/api/presentations/99999999999`…).
 *
 * Ne sont regardés que les paramètres d'adresse nommés `id` ou finissant par `Id`, dont la valeur est tout en chiffres et dépasse
 * 2 147 483 647, et dont la route n'exige pas autre chose qu'un nombre : un identifiant hexadécimal (`[a-f0-9]{16}`) ou un code
 * (`[a-z0-9-]{1,40}`) qui se trouve n'avoir que des chiffres reste valable. Les autres paramètres (code-barres, jeton, année) ne sont
 * jamais regardés. Priorité 7 : après le pare-feu (8), donc une adresse protégée répond toujours 401 ou 403 d'abord ; avant la lecture
 * d'API Platform (4) et la résolution des entités.
 *
 * Coût : rien pour une adresse normale (un test par paramètre). Pour une valeur hors limites, il faut savoir si la route exige autre
 * chose qu'un nombre ; relire la table des routes coûte environ 210 ms et 34 Mo en production (mesuré le 12/11/2026, 825 routes), et une
 * adresse publique peut le déclencher. La courte liste des paramètres à exigence non numérique est donc calculée une fois et gardée dans
 * le cache système, celui que `cache:clear` et la bascule de mise en ligne remplacent en même temps que les routes.
 */
#[AsEventListener(event: 'kernel.request', priority: 7)]
final class OversizedRouteIdListener
{
    public const MAX_INT = 2147483647;
    private const NUMERIC_REQUIREMENTS = ['\d+', '[0-9]+', '[1-9]\d*'];

    public const CACHE_KEY = 'routeid_non_numeric_id_parameters';

    public function __construct(
        private readonly RouterInterface $router,
        #[Autowire(service: 'cache.system')] private readonly CacheInterface $cache,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $params = $request->attributes->get('_route_params');
        foreach (is_array($params) ? $params : [] as $name => $value) {
            if (!is_string($name) || ($name !== 'id' && !str_ends_with($name, 'Id')) || !is_string($value) || !ctype_digit($value)) {
                continue;
            }
            $digits = ltrim($value, '0');
            if (strlen($digits) < 10 || (strlen($digits) === 10 && (int) $digits <= self::MAX_INT)) {
                continue;
            }
            if (isset($this->nonNumericIdParameters()[$request->attributes->get('_route') . '|' . $name])) {
                continue;
            }
            throw new NotFoundHttpException('Not Found');
        }
    }

    /**
     * Paramètres `id` / `…Id` dont la route exige autre chose qu'un nombre : « route|paramètre » => true.
     *
     * @return array<string, true>
     */
    private function nonNumericIdParameters(): array
    {
        return $this->cache->get(self::CACHE_KEY, function (): array {
            $map = [];
            foreach ($this->router->getRouteCollection() as $routeName => $route) {
                foreach ($route->getRequirements() as $name => $requirement) {
                    if (($name === 'id' || str_ends_with($name, 'Id')) && !in_array($requirement, self::NUMERIC_REQUIREMENTS, true)) {
                        $map[$routeName . '|' . $name] = true;
                    }
                }
            }

            return $map;
        });
    }
}
