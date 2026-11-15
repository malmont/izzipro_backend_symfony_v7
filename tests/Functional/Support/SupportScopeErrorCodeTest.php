<?php

namespace App\Tests\Functional\Support;

use App\EventSubscriber\ApiExceptionSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Le code rendu quand la règle d'accès du pare-feu refuse une route (15/11/2026, `docs/assistance.md`) : `SUPPORT_SCOPE_ONLY` pour un
 * compte Assistance SEUL hors de son module, et pour lui seulement ; `FORBIDDEN` dans tous les autres cas (dans le module, compte qui
 * porte un autre rôle d'accès, autre compte, anonyme). Le gestionnaire d'erreurs est exercé directement : les cas « dans le module » et
 * « plusieurs rôles » n'existent sur aucune route d'aujourd'hui, et un test par route ne les atteindrait pas.
 */
class SupportScopeErrorCodeTest extends TestCase
{
    private const SCOPE = ['error' => 'SUPPORT_SCOPE_ONLY', 'code' => 'SUPPORT_SCOPE_ONLY', 'message' => 'Un compte Assistance n\'accède qu\'aux demandes d\'assistance.'];

    public function testOnlyASupportOnlyAccountOutsideItsModuleGetsTheScopeCode(): void
    {
        // Compte Assistance seul, hors du module : le code de sa garde, corps exact, 403
        foreach (['/api/profile', '/api/gestion/statistiques', '/api/site/features', '/%61pi/profile'] as $path) {
            $this->assertSame([403, self::SCOPE], $this->refusal($path, ['ROLE_SUPPORT', 'ROLE_USER']), $path);
        }
        // Dans son module (réservé à l'administrateur, par exemple) : un refus ordinaire, jamais « vous n'accédez qu'à l'assistance » ;
        // le chemin est lu décodé
        foreach (['/api/support/settings', '/api/support', '/api/%73upport/agents'] as $path) {
            $this->assertSame([403, 'FORBIDDEN'], $this->code($path, ['ROLE_SUPPORT', 'ROLE_USER']), $path);
        }
        $this->assertSame([403, self::SCOPE], $this->refusal('/api/supportx', ['ROLE_SUPPORT']), 'un chemin qui commence seulement comme le module n\'est pas le module');
        // Un compte qui porte un autre rôle d'accès n'est pas « Assistance seul », même avec ROLE_SUPPORT
        foreach ([['ROLE_SUPPORT', 'ROLE_ADMIN'], ['ROLE_SUPPORT', 'ROLE_USER_INTERNET'], ['ROLE_SUPPORT', 'ROLE_USER_POS'], ['ROLE_SUPPORT', 'ROLE_SUPER_ADMIN']] as $roles) {
            $this->assertSame([403, 'FORBIDDEN'], $this->code('/api/platform/sites', $roles), implode(' + ', $roles));
        }
        // Les autres comptes et l'anonyme : inchangés
        foreach ([['ROLE_ADMIN'], ['ROLE_USER_INTERNET'], ['ROLE_USER'], null] as $roles) {
            $this->assertSame([403, 'FORBIDDEN'], $this->code('/api/platform/sites', $roles), json_encode($roles));
        }
        // Seul un refus d'accès est concerné : une route inconnue reste « introuvable » pour un compte Assistance seul
        $event = $this->event('/api/inconnue', ['ROLE_SUPPORT'], new NotFoundHttpException('No route found'));
        $this->assertSame([404, 'NOT_FOUND'], [$event->getResponse()->getStatusCode(), json_decode((string) $event->getResponse()->getContent(), true)['code']]);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function refusal(string $path, ?array $roles): array
    {
        $response = $this->event($path, $roles, new AccessDeniedHttpException('Access Denied.'))->getResponse();

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    }

    /** @return array{0: int, 1: ?string} */
    private function code(string $path, ?array $roles): array
    {
        [$status, $body] = $this->refusal($path, $roles);

        return [$status, $body['code'] ?? null];
    }

    private function event(string $path, ?array $roles, \Throwable $exception): ExceptionEvent
    {
        $tokens = new TokenStorage();
        if ($roles !== null) {
            $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('compte@example.invalid', null, $roles), 'api', $roles));
        }
        $event = new ExceptionEvent($this->createMock(HttpKernelInterface::class), Request::create('https://site.test' . $path), HttpKernelInterface::MAIN_REQUEST, $exception);
        (new ApiExceptionSubscriber($tokens))->onKernelException($event);

        return $event;
    }
}
