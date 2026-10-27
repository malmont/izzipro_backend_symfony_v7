<?php

namespace App\Services\AccountService;

use App\Entity\User;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use App\Services\TokenService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session après un changement de courriel (suite de S7) : le jeton JWT et le jeton de renouvellement identifient l'utilisateur par son
 * courriel. Quand l'adresse enregistrée change réellement (casse comprise), les jetons de renouvellement passent à la nouvelle adresse (les
 * autres appareils restent connectés) et la requête de l'utilisateur reçoit de nouveaux jetons, sous la forme de la connexion : cookies de
 * session et jeton CSRF pour le site, token et refresh_token pour les applications. Volontairement explicite (profil, console Mémoires
 * Vivantes, EasyAdmin) : pas de transfert automatique, car la suppression d'un compte anonymise l'adresse puis efface ses jetons.
 */
class SessionIdentityService
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TokenService $tokens,
        private readonly TenantConnectionProvider $tenantConnection,
    ) {
    }

    /** Jetons de renouvellement de l'ancienne adresse rattachés à la nouvelle ; rend le nombre de jetons transférés */
    public function transferRefreshTokens(string $oldEmail, string $newEmail): int
    {
        if ($oldEmail === $newEmail) {
            return 0;
        }

        return (int) $this->emProvider->getEntityManager()->getConnection()
            ->executeStatement('UPDATE refresh_tokens SET username = ? WHERE username = ?', [$newEmail, $oldEmail]);
    }

    /**
     * Nouveaux jetons pour la requête en cours, ajoutés à la réponse : session du site (cookie auth_token_<site> présent) → cookies
     * (auth_token, refresh_token, XSRF-TOKEN) et {token, refresh_token, csrf_token} ; sinon (Bearer : applications, MAUI) → {token, refresh_token}.
     *
     * @return array<string, string> clés à ajouter au corps de la réponse
     */
    public function reissue(User $user, Request $request, Response $response): array
    {
        $web = $request->cookies->has('auth_token_' . ($this->tenantConnection->getTenantCode() ?? 'default'));
        $tokens = $this->tokens->generateTokens($user, $web ? 'web' : null);
        if ($web) {
            $tokens['csrf_token'] = $this->tokens->attachWebSessionCookies($response, $tokens['token'], $tokens['refresh_token']);
        }

        return $tokens;
    }
}
