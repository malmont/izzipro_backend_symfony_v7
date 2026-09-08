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

class TenantUserProvider implements UserProviderInterface
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

        $user = $em->getRepository(User::class)->findOneBy(['email' => $identifier])
             ?? $em->getRepository(User::class)->findOneBy(['username' => $identifier])
             ?? $em->getRepository(EsgUser::class)->findOneBy(['email' => $identifier]);

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
}
