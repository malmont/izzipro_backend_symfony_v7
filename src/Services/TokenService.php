<?php
// src/Service/TokenService.php

namespace App\Services;

use DateTime;
use App\Services\TenantEntityManagerProvider;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;

class TokenService
{
    private JWTTokenManagerInterface $JWTManager;
    private TenantEntityManagerProvider $tenantEmProvider;
    private TenantConnectionProvider $tenantConnProvider;

    /**
     * Session web (cookies) : jeton d'accès court et fenêtre d'inactivité glissante.
     * Chaque rafraîchissement repousse la fin de session ; sans activité pendant $webSessionIdleTtl secondes,
     * le jeton de rafraîchissement expire et l'utilisateur est déconnecté. Mobile / POS : inchangé (7 jours).
     */
    public function __construct(
        JWTTokenManagerInterface $JWTManager,
        TenantEntityManagerProvider $tenantEmProvider,
        TenantConnectionProvider $tenantConnProvider,
        private int $webAccessTokenTtl = 900,
        private int $webSessionIdleTtl = 3600
    ) {
        $this->JWTManager = $JWTManager;
        $this->tenantEmProvider = $tenantEmProvider;
        $this->tenantConnProvider = $tenantConnProvider;
    }

    /**
     * Génère un token JWT et crée un refresh token associé.
     *
     * @param UserInterface $user
     * @param string|null $platform "web" : durées de la session web (cookies), sinon 7 jours
     * @return array Un tableau contenant 'token' et 'refresh_token'
     */
    public function generateTokens(UserInterface $user, ?string $platform = null): array
    {
        $isWeb = $platform === 'web';

        $jwt = $isWeb ? $this->createWebAccessToken($user) : $this->JWTManager->create($user);

        $em = $this->tenantEmProvider->getEntityManager();


        $refreshToken = new RefreshToken();
        $refreshToken->setRefreshToken(base64_encode(random_bytes(64)));
        $refreshToken->setUsername($user->getUserIdentifier());
        $refreshToken->setValid($isWeb ? $this->webSessionExpiry() : (new DateTime())->modify('+7 days'));
        $em->persist($refreshToken);
        $em->flush();

        return [
            'token' => $jwt,
            'refresh_token' => $refreshToken->getRefreshToken(),
        ];
    }

    /** Jeton d'accès web : même durée que son cookie (le jeton du corps ne survit pas au cookie) */
    public function createWebAccessToken(UserInterface $user): string
    {
        return $this->JWTManager->createFromPayload($user, ['exp' => time() + $this->webAccessTokenTtl]);
    }

    /** Fin de session web si aucune activité d'ici là */
    public function webSessionExpiry(): DateTime
    {
        return (new DateTime())->modify('+' . $this->webSessionIdleTtl . ' seconds');
    }

    /**
     * Pose les cookies de session web (jeton d'accès, refresh token, CSRF) et renvoie la valeur CSRF.
     */
    public function attachWebSessionCookies(Response $response, string $jwt, string $refreshToken, string $cookiePath = '/'): string
    {
        // Cookie Host-Only (domain=null) : indispensable en multi-domaine et derrière reverse-proxy
        // pour éviter que le navigateur rejette le cookie à cause d'une discordance de domaine.
        $cookieDomain = null;

        // Isolation Multi-Tenant par Suffixe du Cookie
        // Comme le domaine peut être partagé (.gem-backend.online via wildcard navigateur), on différencie par le NOM
        $tenantCode = $this->tenantConnProvider->getTenantCode() ?? 'default';

        $cookie = fn (string $name, string $value, int $ttl, bool $httpOnly) => Cookie::create($name)
            ->withValue($value)
            ->withHttpOnly($httpOnly)
            ->withSecure(true)
            ->withSameSite(Cookie::SAMESITE_NONE)
            ->withExpires(time() + $ttl)
            ->withPath($cookiePath)
            ->withDomain($cookieDomain);

        // Réintroduction CSRF - Paramètres CRITIQUES (Isolation Multi-Tenant)
        $csrfTokenValue = bin2hex(random_bytes(32));

        $response->headers->setCookie($cookie('auth_token_' . $tenantCode, $jwt, $this->webAccessTokenTtl, true));
        $response->headers->setCookie($cookie('refresh_token_' . $tenantCode, $refreshToken, $this->webSessionIdleTtl, true));
        $response->headers->setCookie($cookie('XSRF-TOKEN_' . $tenantCode, $csrfTokenValue, $this->webAccessTokenTtl, false));

        return $csrfTokenValue;
    }

    /**
     * Crée une réponse HTTP contenant les tokens en JSON et, si la plateforme est web, ajoute les cookies.
     *
     * @param array $tokens Tableau contenant 'token' et 'refresh_token'
     * @param string $platform (par exemple "web" ou "mobile")
     * @param string $cookiePath Chemin du cookie (par défaut "/")
     * @return Response
     */
    public function createResponseWithTokens(
        array $tokens,
        string $platform,
        string $host,
        string $cookiePath = '/'
    ): Response {
        $response = new Response();

        if ($platform === 'web') {
            $tokens['csrf_token'] = $this->attachWebSessionCookies($response, $tokens['token'], $tokens['refresh_token'], $cookiePath);
        }

        $response->setContent(json_encode($tokens));
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }
}
