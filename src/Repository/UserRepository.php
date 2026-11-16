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
     * Le compte d'une adresse, QUELLE QUE SOIT SA CASSE (et ses espaces de bord) : « Paul@Exemple.ca » trouve « paul@exemple.ca ».
     * Lot SÉCURITÉ-2 (16/11/2026) : une adresse ne désigne qu'un compte, à la casse près (index unique sur l'adresse en minuscules) ;
     * la connexion, le mot de passe oublié, les invitations et les contrôles d'unicité cherchent ainsi. Rien n'est réécrit dans les
     * adresses gardées : on compare seulement en minuscules. S'il restait deux lignes (base pas encore migrée), la plus ancienne.
     */
    public function findOneByEmailCaseless(mixed $email): ?User
    {
        if (!is_string($email) || trim($email) === '') {
            return null;
        }

        // (les minuscules faites par la base, des deux côtés : la même règle que l'index unique)
        return $this->createQueryBuilder('u')->where('LOWER(u.email) = LOWER(:email)')->setParameter('email', trim($email))
            ->orderBy('u.id', 'ASC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /**
     * Pour le contrôle d'unicité de l'entité (`UniqueEntity`, lot SÉCURITÉ-2) : les comptes de cette adresse, à la casse près. Un
     * formulaire (EasyAdmin, inscription) refuse ainsi une adresse prise à une majuscule près, au lieu d'une erreur de la base.
     *
     * @param array<string, mixed> $criteria
     * @return list<User>
     */
    public function findByEmailCaseless(array $criteria): array
    {
        $user = $this->findOneByEmailCaseless($criteria['email'] ?? null);

        return $user === null ? [] : [$user];
    }

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