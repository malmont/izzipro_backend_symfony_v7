<?php
// src/Security/TenantUserProvider.php

namespace App\Security;

use App\Entity\User;
use App\ESG\Entity\EsgUser;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Utilisateurs du site courant. Jeton d'accès (suite de S7, 27/10/2026) : chargé par l'identifiant numérique du jeton (claim « id »), et
 * seulement si ce compte porte encore l'adresse ou le nom d'utilisateur du jeton : un ancien jeton n'ouvre jamais un autre compte créé
 * ensuite avec l'ancienne adresse, ni un compte supprimé (anonymisé). Jetons sans « id » et comptes Boussole : chemin d'avant.
 */
class TenantUserProvider implements UserProviderInterface, \Lexik\Bundle\JWTAuthenticationBundle\Security\User\PayloadAwareUserProviderInterface
{
    public function __construct(
        private readonly TenantEntityManagerProvider $tenantEmProvider,
        private readonly LoggerInterface $logger
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        // On récupère l'EM du tenant, pas le "default"
        $em = $this->tenantEmProvider->getEntityManager();

        $dbName = $em->getConnection()->getDatabase();

        if (str_starts_with($identifier, EsgUser::IDENTIFIER_PREFIX)) {
            // Jeton d'un compte ESG : uniquement parmi les comptes ESG, jamais parmi les utilisateurs classiques
            $user = $em->getRepository(EsgUser::class)->findOneBy(['email' => substr($identifier, strlen(EsgUser::IDENTIFIER_PREFIX))]);
        } else {
            // Utilisateurs classiques ; repli ESG pour les jetons émis avant le préfixe (e-mail seul)
            $user = $em->getRepository(User::class)->findOneBy(['email' => $identifier])
                 ?? $em->getRepository(User::class)->findOneBy(['username' => $identifier])
                 ?? $em->getRepository(EsgUser::class)->findOneBy(['email' => $identifier]);
        }

        if (!$user) {
            $this->logger->warning(
                "Authentication: utilisateur '{user}' NON TROUVÉ sur '{db}'",
                ['user' => $identifier, 'db' => $dbName]
            );
            throw new UserNotFoundException(
                sprintf('User "%s" not found.', $identifier)
            );
        }

        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $class === User::class 
            || is_subclass_of($class, User::class)
            || $class === EsgUser::class
            || is_subclass_of($class, EsgUser::class);
    }

    /** Jeton d'accès : compte désigné par le claim « id », qui doit encore porter l'adresse ou le nom d'utilisateur du jeton */
    public function loadUserByIdentifierAndPayload(string $identifier, array $payload): UserInterface
    {
        if (str_starts_with($identifier, EsgUser::IDENTIFIER_PREFIX) || !isset($payload['id']) || !is_numeric($payload['id'])) {
            return $this->loadUserByIdentifier($identifier);
        }
        $user = $this->tenantEmProvider->getEntityManager()->find(User::class, (int) $payload['id']);
        if (!$user instanceof User || ($user->getEmail() !== $identifier && $user->getUsername() !== $identifier)) {
            throw new UserNotFoundException(sprintf('Jeton du compte %d sans correspondance (adresse changée ou compte supprimé).', (int) $payload['id']));
        }

        return $user;
    }

    /** @deprecated interface de lexik antérieure à 2.12 : même règle */
    public function loadUserByUsernameAndPayload(string $username, array $payload): UserInterface
    {
        return $this->loadUserByIdentifierAndPayload($username, $payload);
    }
}
