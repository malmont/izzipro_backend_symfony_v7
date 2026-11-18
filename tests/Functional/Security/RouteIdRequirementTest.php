<?php

namespace App\Tests\Functional\Security;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * GARDE-FOU (lot G-1, 18/11/2026, demande du chef) : une route de l'API dont un paramètre d'identifiant (`{id}`, `{…Id}`, `{…_id}`) n'a pas
 * d'exigence accepte « 5%20 », « +5 » ou « 5. » : PHP (ou PostgreSQL) y lit 5, et une règle de garde ancrée sur `\d+$` passe à côté
 * (relecture neuve de G-1 : `/api/order/cancel/5%20`). Toute route nouvelle de ce genre fait échouer la suite, sauf si elle est inscrite
 * ci-dessous avec sa raison.
 */
class RouteIdRequirementTest extends KernelTestCase
{
    /** Préfixes de nom ou de chemin exemptés, avec leur raison */
    private const EXEMPT_PREFIXES = [
        'name:_api_' => 'routes générées par API Platform : le fournisseur de la ressource relit l\'identifiant',
        'path:/api/memoires/' => 'Mémoires Vivantes, projet à part (décision du 16/11/2026) : fermé à l\'agence et au directeur d\'un groupe',
    ];
    /** Routes exemptées une à une, avec leur raison */
    private const EXEMPT_ROUTES = [
        'api_genid' => 'API Platform (identifiants générés)',
        'api_validation_errors' => 'API Platform (erreurs de validation, identifiant textuel)',
        'api_landingpage_ai_job' => 'identifiant TEXTUEL de tâche de fond (chaîne aléatoire), jamais un entier',
    ];

    public function testEveryIdParameterOfTheApiHasARequirementOrAWrittenReason(): void
    {
        self::bootKernel();
        $missing = [];
        $exemptUsed = [];
        foreach (static::getContainer()->get('router')->getRouteCollection() as $name => $route) {
            $path = $route->getPath();
            if (!str_starts_with($path, '/api/')) {
                continue;
            }
            preg_match_all('/\{([^}]+)\}/', $path, $m);
            foreach ($m[1] as $param) {
                if (($param !== 'id' && !str_ends_with($param, 'Id') && !str_ends_with($param, '_id')) || $route->getRequirement($param) !== null) {
                    continue;
                }
                $reason = self::EXEMPT_ROUTES[$name] ?? null;
                foreach (self::EXEMPT_PREFIXES as $prefix => $why) {
                    [$kind, $value] = explode(':', $prefix, 2);
                    if (str_starts_with($kind === 'name' ? $name : $path, $value)) {
                        $reason ??= $why;
                    }
                }
                if ($reason === null) {
                    $missing[] = "$name $path {{$param}}";
                } elseif (isset(self::EXEMPT_ROUTES[$name])) {
                    $exemptUsed[$name] = true;
                }
            }
        }
        sort($missing);
        $this->assertSame([], $missing, 'identifiant sans exigence : ajouter requirements: [\'id\' => \'\\d+\'] (ou l\'inscrire ici avec sa raison)');
        $this->assertSame([], array_values(array_diff(array_keys(self::EXEMPT_ROUTES), array_keys($exemptUsed))), 'une exception inscrite ne sert plus : la retirer');
    }
}
