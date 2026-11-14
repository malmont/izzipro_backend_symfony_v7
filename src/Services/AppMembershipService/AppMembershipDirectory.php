<?php

namespace App\Services\AppMembershipService;

use App\Entity\User;
use App\Entity\UserAppMembership;
use App\Repository\UserAppMembershipRepository;
use App\Security\AppFamilies;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Lecture des appartenances pour les listes d'administration (APPS-MEMBRES, lot M4, `docs/apps-membres.md`) : la colonne et le filtre
 * « Applications » d'EasyAdmin, la console Mémoires Vivantes, la lecture de profil. **Lecture seule** : rien ici n'ajoute ni ne retire
 * un client d'une famille. Les appartenances du site sont lues en une requête par requête HTTP (la table est petite), gardées par site.
 */
class AppMembershipDirectory implements ResetInterface
{
    /** Valeurs du filtre en plus des familles : un compte client sans aucune appartenance ; un compte d'équipe (jamais membre) */
    public const NONE = 'none';
    public const TEAM = 'team';
    public const CHOICES = [AppFamilies::BOUTIQUE, AppFamilies::LIVINGMEMORY, AppFamilies::POS, self::NONE, self::TEAM];

    /** Libellés de l'administration (français, comme le reste d'EasyAdmin) */
    public const LABELS = [AppFamilies::BOUTIQUE => 'Boutique', AppFamilies::LIVINGMEMORY => 'Living Memory', AppFamilies::POS => 'Caisse', self::NONE => 'Aucune', self::TEAM => 'Équipe'];

    /** D'où vient une appartenance, dit à l'administrateur */
    public const SOURCE_LABELS = [
        AppFamilies::SOURCE_REGISTER => 'Inscription', AppFamilies::SOURCE_JOIN => 'A rejoint', AppFamilies::SOURCE_GUEST_CHECKOUT => 'Commande en invité',
        AppFamilies::SOURCE_POS_LINK => 'Fiche de caisse', AppFamilies::SOURCE_BACKFILL => 'Reprise de l\'historique',
    ];

    /** @var array<string, array<int, list<UserAppMembership>>> site => compte => appartenances */
    private array $bySite = [];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly TenantConnectionProvider $tenant)
    {
    }

    public function reset(): void
    {
        $this->bySite = [];
    }

    /** @return list<UserAppMembership> appartenances d'un compte, de la plus ancienne à la plus récente */
    public function of(int $userId): array
    {
        return $this->all()[$userId] ?? [];
    }

    /** @return list<string> familles d'un compte, dans l'ordre de `AppFamilies::ALL` */
    public function families(int $userId): array
    {
        $apps = array_map(fn (UserAppMembership $m) => $m->getApp(), $this->of($userId));

        return array_values(array_filter(AppFamilies::ALL, fn (string $family) => in_array($family, $apps, true)));
    }

    /** L'appartenance d'un compte à une famille, ou null */
    public function membership(int $userId, string $family): ?UserAppMembership
    {
        foreach ($this->of($userId) as $membership) {
            if ($membership->getApp() === $family) {
                return $membership;
            }
        }

        return null;
    }

    /**
     * Ce que la colonne « Applications » montre pour un compte, en valeurs de `CHOICES` : « team » pour un compte d'équipe, puis ses
     * familles ; « none » quand il n'a ni l'un ni l'autre. Le filtre rend exactement les comptes dont la colonne porte la valeur.
     *
     * @return list<string>
     */
    public function applications(User $user): array
    {
        $shown = [...(AppFamilies::isTeam($user) ? [self::TEAM] : []), ...$this->families((int) $user->getId())];

        return $shown === [] ? [self::NONE] : $shown;
    }

    /** @return list<int> comptes du site dont la colonne « Applications » porte cette valeur ; valeur inconnue : aucun */
    public function userIds(string $choice): array
    {
        if (!in_array($choice, self::CHOICES, true)) {
            return [];
        }
        $ids = [];
        foreach ($this->repository()->backfillAccounts() as $account) {
            $shown = [...(AppFamilies::isTeam($account['roles']) ? [self::TEAM] : []), ...$this->families($account['id'])];
            if (in_array($choice, $shown === [] ? [self::NONE] : $shown, true)) {
                $ids[] = $account['id'];
            }
        }

        return $ids;
    }

    /** @return array<int, list<UserAppMembership>> */
    private function all(): array
    {
        return $this->bySite[$this->tenant->getTenantCode() ?? ''] ??= $this->repository()->allByUser();
    }

    private function repository(): UserAppMembershipRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(UserAppMembership::class);
    }
}
