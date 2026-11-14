<?php

namespace App\Tests\Functional\Security;

use PHPUnit\Framework\TestCase;

/**
 * Garde-fou du lot CHEMIN (14/11/2026) : aucun fichier de `src/` ne lit le chemin BRUT d'une requête (`getPathInfo()`,
 * `getRequestUri()`) pour décider quoi que ce soit. Le routeur et les règles d'accès décodent le chemin ; une garde qui compare le
 * chemin brut se contourne en encodant une lettre (`/api/%6cogin`). Tout passe par `App\Services\Http\RequestPath::of()`.
 *
 * Un nouvel endroit fait échouer ce test : employer `RequestPath::of($request)`, ou l'inscrire ici avec sa raison s'il ne décide rien.
 */
class RequestPathGuardTest extends TestCase
{
    /** « fichier » => raison : lecture du chemin brut admise parce qu'elle ne décide de rien */
    private const ALLOWED = [
        'src/Services/Http/RequestPath.php' => 'la lecture commune elle-même',
        'src/Services/PosAuditService/PosIdempotencyService.php' => 'partie d\'une clé d\'idempotence (le même appel rejoué a le même chemin brut) : ne garde rien',
        'src/EventSubscriber/RetentionGuardSubscriber.php' => 'libellé d\'une entrée de journal, quand la route n\'a pas de nom : ne garde rien',
    ];

    public function testNoGuardReadsTheRawPathOfARequest(): void
    {
        $root = dirname(__DIR__, 3) . '/';
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . 'src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root));
            foreach (file($file->getPathname()) as $n => $line) {
                $code = preg_replace('#^\s*(//|\*|/\*).*$#', '', $line); // les commentaires peuvent en parler
                if (preg_match('/->(getPathInfo|getRequestUri)\(\)/', (string) $code)) {
                    $found[$relative][] = $n + 1;
                }
            }
        }
        $unexpected = array_diff_key($found, self::ALLOWED);
        $this->assertSame([], array_map(fn (array $lines) => implode(', ', $lines), $unexpected),
            "Lecture du chemin brut d'une requête : employer App\\Services\\Http\\RequestPath::of(\$request) (le chemin que voit le routeur).");
        $this->assertSame([], array_keys(array_diff_key(self::ALLOWED, $found)), 'Fichier inscrit qui ne lit plus le chemin brut : à retirer de la liste.');
    }

    public function testThePathIsDecodedOnceLikeTheRouterDoes(): void
    {
        $path = fn (string $uri): string => \App\Services\Http\RequestPath::of(\Symfony\Component\HttpFoundation\Request::create($uri));
        $this->assertSame(['/api/login', '/api/login', '/api/login', '/api/products/by-slug/café', '/%61pi/profile', '/api/gestion', '/api/a+b'],
            [$path('/api/login'), $path('/api/%6cogin'), $path('/%61%70%69/%6C%6F%67%69%6E'), $path('/api/products/by-slug/caf%C3%A9'), $path('/%2561pi/profile'), $path('/api%2Fgestion?x=%2F'),
                $path('/api/a+b')], 'décodé une seule fois ; un « + » reste un « + », comme pour le routeur');
    }
}
