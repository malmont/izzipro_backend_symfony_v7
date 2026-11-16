<?php

namespace App\Security;

use App\Entity\User;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

/**
 * Qui regarde un site en construction (lot CONSTRUCTION, `docs/boutique-en-construction.md`) : les rôles du compte de la requête.
 *
 * Plusieurs lectures publiques de la boutique (transporteurs, formules, équipe, véhicules…) et trois écritures (commande d'invité,
 * panier chiffré, intention de paiement) sont HORS du pare-feu de l'API : le jeton n'y est pas lu, `Security::getUser()` y est vide,
 * même pour un administrateur connecté. Sans ce résolveur, l'administrateur verrait sa propre boutique refusée sur ces routes. Le
 * jeton du site (cookie `auth_token_<site>` ou en-tête `Authorization`) est donc décodé comme par le pare-feu (signature, expiration,
 * site du jeton : `JWTDecodedListener`), et les rôles sont RELUS en base, jamais pris du jeton. Jeton absent, expiré ou d'un autre
 * site : aucun rôle (un visiteur).
 */
class StorefrontViewer
{
    public function __construct(
        private readonly Security $security,
        private readonly JWTTokenManagerInterface $jwt,
        private readonly TenantConnectionProvider $tenantProvider,
        private readonly TenantEntityManagerProvider $emProvider,
    ) {
    }

    /** @return list<string> les rôles du compte de la requête, vide pour un visiteur */
    public function roles(Request $request): array
    {
        $user = $this->security->getUser();
        if ($user !== null) {
            return array_values($user->getRoles());
        }
        $code = $this->tenantProvider->getTenantCode();
        $token = $code !== null ? $request->cookies->get('auth_token_' . $code) : null;
        if (!$token && preg_match('/^Bearer\s+(\S+)$/i', (string) $request->headers->get('Authorization'), $m)) {
            $token = $m[1];
        }
        if (!is_string($token) || $token === '') {
            return [];
        }
        try {
            $payload = $this->jwt->parse($token);
            if (!isset($payload['id']) || !is_numeric($payload['id'])) {
                return [];
            }
            $identifier = $payload['username'] ?? $payload['email'] ?? null;
            $account = $this->emProvider->getEntityManager()->find(User::class, (int) $payload['id']);

            return $account instanceof User && is_string($identifier) && ($account->getEmail() === $identifier || $account->getUsername() === $identifier) ? array_values($account->getRoles()) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** Un compte d'équipe du site (pas un client) : il voit la vraie boutique sans lien d'aperçu */
    public function isTeam(Request $request): bool
    {
        return StorefrontGateSubscriber::isTeam($this->roles($request));
    }
}
