<?php
// Fichier : /var/www/EcommerceSymfony/src/Security/GemsuiteJwtAuthenticator.php

namespace App\Security;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class GemsuiteJwtAuthenticator extends AbstractAuthenticator
{
    private string $gemsuitePublicKeyPath;

    public function __construct(string $projectDir)
    {
        $this->gemsuitePublicKeyPath = $projectDir . '/config/secrets/test/gemsuite_jwt_public.pem';
    }

    public function supports(Request $request): ?bool
    {
        return false; // Désactivé en mode Standalone
    }

    public function authenticate(Request $request): Passport
    {
        // 1. Vérification mTLS (passée par NGINX)
        if ($request->server->get('SSL_CLIENT_VERIFY') !== 'SUCCESS') {
            throw new AuthenticationException('La vérification du certificat client (mTLS) a échoué.');
        }

        // 2. Vérification du header Authorization
        $authHeader = $request->headers->get('Authorization');
        if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            throw new AuthenticationException('Token JWT manquant ou mal formé.');
        }
        $jwt = $matches[1];

        // 3. Vérification du JWT
        try {
            $publicKey = file_get_contents($this->gemsuitePublicKeyPath);
            $decoded = JWT::decode($jwt, new Key($publicKey, 'RS256'));

            if ($decoded->iss !== 'gemsuite-api' || $decoded->aud !== 'iizipro-bff') {
                throw new \Exception('Issuer ou Audience invalide.');
            }
        } catch (\Exception $e) {
            throw new AuthenticationException('Token JWT invalide: ' . $e->getMessage());
        }

        // Si tout est bon, on crée un passeport auto-validé.
        // On pourrait charger un "User" de service si nécessaire.
        return new SelfValidatingPassport(new UserBadge('gemsuite_service'));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // On laisse la requête continuer vers le contrôleur
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(
            ['error' => 'Authentification échouée', 'message' => $exception->getMessage()],
            Response::HTTP_UNAUTHORIZED
        );
    }
}