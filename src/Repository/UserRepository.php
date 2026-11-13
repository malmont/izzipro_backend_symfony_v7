<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\ORM\EntityRepository; // MODIFIÉ : On utilise le repository de base
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * N'est plus un service Symfony. L'interface est conservée.
 * @extends EntityRepository<User>
 */
class UserRepository extends EntityRepository implements PasswordUpgraderInterface
{
    /**
     * SUPPRIMÉ : Le constructeur n'est plus nécessaire.
     */
    // public function __construct(ManagerRegistry $registry) { ... }


    /**
     * Comptes portant un rôle (colonne JSON lue en texte), par nom puis prénom ; rôles d'équipe (13/11/2026).
     *
     * @return list<User>
     */
    public function findByRole(string $role): array
    {
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT id FROM "user" WHERE roles::text LIKE :like ORDER BY lower(lastname), lower(firstname), id',
            ['like' => '%"' . $role . '"%']
        );
        if ($ids === []) {
            return [];
        }
        $byId = [];
        foreach ($this->findBy(['id' => $ids]) as $user) {
            $byId[$user->getId()] = $user;
        }

        return array_values(array_filter(array_map(fn ($id) => $byId[(int) $id] ?? null, $ids)));
    }

    public function save(User $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(User $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * INCHANGÉ : Cette méthode fonctionnera maintenant avec le bon EntityManager
     * car $this->save() utilisera la connexion du tenant.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', \get_class($user)));
        }

        $user->setPassword($newHashedPassword);

        $this->save($user, true);
    }
}