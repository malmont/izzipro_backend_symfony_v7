<?php

namespace App\Security;

use App\Entity\User;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Client connecté d'une route publique hors du pare-feu JWT (create-intent, 09/10/2026) : jeton du site (cookie
 * auth_token_<site> ou en-tête Authorization) décodé comme par le pare-feu (signature, expiration, site du jeton :
 * JWTDecodedListener). Jeton absent, expiré ou d'un autre site : null (le visiteur est traité en invité, sans erreur).
 */
final class OptionalCustomerResolver
{
    public function __construct(
        private readonly JWTTokenManagerInterface $jwt,
        private readonly TenantConnectionProvider $tenantProvider,
        private readonly TenantEntityManagerProvider $emProvider
    ) {
    }

    public function resolve(Request $request): ?User
    {
        $code = $this->tenantProvider->getTenantCode();
        $token = $code !== null ? $request->cookies->get('auth_token_' . $code) : null;
        if (!$token && preg_match('/^Bearer\s+(\S+)$/i', (string) $request->headers->get('Authorization'), $m)) {
            $token = $m[1];
        }
        if (!is_string($token) || $token === '') {
            return null;
        }
        try {
            $payload = $this->jwt->parse($token);
        } catch (\Throwable) {
            return null;
        }
        $identifier = $payload['username'] ?? $payload['email'] ?? null;
        if (!is_string($identifier) || $identifier === '') {
            return null;
        }
        $user = $this->emProvider->getEntityManager()->getRepository(User::class)->findOneBy(['email' => $identifier]);

        return $user instanceof User ? $user : null;
    }
}
