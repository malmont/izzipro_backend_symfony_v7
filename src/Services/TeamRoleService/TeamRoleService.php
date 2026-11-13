<?php

namespace App\Services\TeamRoleService;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\TeamRoles;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantEntityManagerProvider;

/**
 * Attribution et retrait des rôles d'équipe (App\Security\TeamRoles), journalisés dans content_audit_log
 * (ressource `user_team`, action `grant` ou `revoke`, états avant / après = rôles du compte, champ = le rôle).
 * Point d'entrée unique : console Équipe de Mémoires Vivantes, écran EasyAdmin des utilisateurs, commande
 * app:memoires:team:grant. Toute application qui ajoute un rôle d'équipe réutilise ce service tel quel.
 */
final class TeamRoleService
{
    public const ACTION_GRANT = 'grant';
    public const ACTION_REVOKE = 'revoke';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly ContentAuditRecorder $audit
    ) {
    }

    /**
     * Pose ou retire un rôle d'équipe ; idempotent (rien n'est écrit ni journalisé si le compte est déjà dans l'état voulu).
     *
     * @param list<string> $marks repères ajoutés aux champs du journal (ex. `console` pour la commande)
     * @return bool vrai si le compte a changé
     */
    public function set(User $user, string $role, bool $member, array $marks = []): bool
    {
        if (!TeamRoles::isTeamRole($role)) {
            throw new \InvalidArgumentException(sprintf('%s n\'est pas un rôle d\'équipe.', $role));
        }
        $before = $this->storedRoles($user);
        $has = in_array($role, $before, true);
        if ($has === $member) {
            return false;
        }
        $after = $member ? TeamRoles::with($before, $role) : TeamRoles::without($before, $role);
        $user->setRoles($after);
        $this->emProvider->getEntityManager()->flush();
        $this->audit->record(TeamRoles::AUDIT_RESOURCE, $user->getId(), $member ? self::ACTION_GRANT : self::ACTION_REVOKE, $before, $after, [$role, ...$marks]);

        return true;
    }

    /**
     * Journalise les rôles d'équipe gagnés ou perdus entre deux états (écran EasyAdmin, qui enregistre lui-même les rôles).
     *
     * @param list<string> $before
     * @param list<string> $after
     */
    public function recordDiff(User $user, array $before, array $after): void
    {
        foreach (TeamRoles::ALL as $role) {
            $had = in_array($role, $before, true);
            $has = in_array($role, $after, true);
            if ($had !== $has) {
                $this->audit->record(TeamRoles::AUDIT_RESOURCE, $user->getId(), $has ? self::ACTION_GRANT : self::ACTION_REVOKE, $before, $after, [$role, 'easyadmin']);
            }
        }
    }

    /** @return list<User> membres portant le rôle, par nom puis prénom */
    public function members(string $role): array
    {
        /** @var UserRepository $repo */
        $repo = $this->emProvider->getEntityManager()->getRepository(User::class);

        return $repo->findByRole($role);
    }

    /** @return list<string> rôles enregistrés (sans le ROLE_USER implicite de getRoles()) */
    private function storedRoles(User $user): array
    {
        return array_values(array_diff($user->getRoles(), ['ROLE_USER']));
    }
}
