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
    /** Lot IMP-2 : compte repris du fichier des clients d'une ancienne boutique, qui n'a pas (encore) accepté son invitation : pas un client */
    public const INVITED = 'invited';
    public const CHOICES = [AppFamilies::BOUTIQUE, AppFamilies::LIVINGMEMORY, AppFamilies::POS, self::NONE, self::TEAM, self::INVITED];

    /** Libellés de l'administration (français, comme le reste d'EasyAdmin) */
    public const LABELS = [AppFamilies::BOUTIQUE => 'Boutique', AppFamilies::LIVINGMEMORY => 'Living Memory', AppFamilies::POS => 'Caisse', self::NONE => 'Aucune', self::TEAM => 'Équipe', self::INVITED => 'Invité (boutique importée)'];

    /** D'où vient une appartenance, dit à l'administrateur */
    public const SOURCE_LABELS = [
        AppFamilies::SOURCE_REGISTER => 'Inscription', AppFamilies::SOURCE_JOIN => 'A rejoint', AppFamilies::SOURCE_GUEST_CHECKOUT => 'Commande en invité',
        AppFamilies::SOURCE_POS_LINK => 'Fiche de caisse', AppFamilies::SOURCE_BACKFILL => 'Reprise de l\'historique',
        AppFamilies::SOURCE_IMPORT => 'Invitation acceptée (boutique importée)',
    ];

    /** @var array<string, array<int, list<UserAppMembership>>> site => compte => appartenances */
    private array $bySite = [];

    /** @var array<string, array<int, true>> site => comptes invités par import en attente */
    private array $invitedBySite = [];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly TenantConnectionProvider $tenant)
    {
    }

    public function reset(): void
    {
        $this->bySite = [];
        $this->invitedBySite = [];
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

        return $shown === [] ? [isset($this->invited()[(int) $user->getId()]) ? self::INVITED : self::NONE] : $shown;
    }

    /**
     * Lot IMP-2 : les comptes « invités par import » du site qui n'ont pas accepté leur invitation (`import_customer_invitation`). Un
     * invité n'est membre de rien : sa colonne dit « Invité » au lieu de « Aucune ».
     *
     * @return array<int, true>
     */
    private function invited(): array
    {
        $site = $this->tenant->getTenantCode() ?? '';
        if (!isset($this->invitedBySite[$site])) {
            try {
                $ids = $this->emProvider->getEntityManager()->getConnection()->fetchFirstColumn("SELECT user_id FROM import_customer_invitation WHERE state IN ('imported', 'invited')");
            } catch (\Throwable) {
                $ids = []; // table absente d'une base qui n'a pas encore sa migration : aucun invité
            }
            $this->invitedBySite[$site] = array_fill_keys(array_map('intval', $ids), true);
        }

        return $this->invitedBySite[$site];
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
            if (in_array($choice, $shown === [] ? [isset($this->invited()[(int) $account['id']]) ? self::INVITED : self::NONE] : $shown, true)) {
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
