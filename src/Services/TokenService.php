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

    public function __construct(JWTTokenManagerInterface $JWTManager, TenantEntityManagerProvider $tenantEmProvider)
    {
        $this->JWTManager = $JWTManager;
        $this->tenantEmProvider = $tenantEmProvider;
    }

    /**
     * Génère un token JWT et crée un refresh token associé.
     *
     * @param UserInterface $user
     * @return array Un tableau contenant 'token' et 'refresh_token'
     */
    public function generateTokens(UserInterface $user): array
    {

        $jwt = $this->JWTManager->create($user);

        $em = $this->tenantEmProvider->getEntityManager();

 
        $refreshToken = new RefreshToken();
        $refreshToken->setRefreshToken(base64_encode(random_bytes(64)));
        $refreshToken->setUsername($user->getUserIdentifier());
        $refreshToken->setValid((new DateTime())->modify('+7 days'));
        $em->persist($refreshToken);
        $em->flush();

        return [
            'token' => $jwt,
            'refresh_token' => $refreshToken->getRefreshToken(),
        ];
    }

    /**
     * Crée une réponse HTTP contenant les tokens en JSON et, si la plateforme est web, ajoute les cookies.
     *
     * @param array $tokens Tableau contenant 'token' et 'refresh_token'
     * @param string $platform (par exemple "web" ou "mobile")
     * @param int $ttlJwt Durée de vie du JWT en secondes (par défaut 3600)
     * @param int $ttlRefresh Durée de vie du refresh token en secondes (par défaut 604800)
     * @param string $cookiePath Chemin du cookie (par défaut "/")
     * @return Response
     */
     public function createResponseWithTokens(
        array $tokens, 
        string $platform,
        string $host, 
        int $ttlJwt = 3600, 
        int $ttlRefresh = 604800, 
        string $cookiePath = '/'
    ): Response
    {
        $response = new Response();

        if ($platform === 'web') {
            $jwtCookie = Cookie::create('jwt')
                ->withValue($tokens['token'])
                ->withHttpOnly(true)
                ->withSecure(true)
                ->withSameSite(Cookie::SAMESITE_NONE) 
                ->withExpires(time() + $ttlJwt)
                ->withPath($cookiePath)
                ->withDomain($host); 
            
            $refreshCookie = Cookie::create('refresh_token')
                ->withValue($tokens['refresh_token'])
                ->withHttpOnly(true)
                ->withSecure(true)
                ->withSameSite(Cookie::SAMESITE_NONE)
                ->withExpires(time() + $ttlRefresh)
                ->withPath($cookiePath)
                ->withDomain($host); 

            $response->headers->setCookie($jwtCookie);
            $response->headers->setCookie($refreshCookie);
        }

        $response->setContent(json_encode($tokens));
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }
}
