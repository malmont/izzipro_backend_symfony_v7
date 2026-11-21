<?php

namespace App\Abo\Services;

use App\Security\TeamRoles;
use App\Services\TenantEntityManagerProvider;

/**
 * Qui peut être intervenant des abonnements (lot ABO-3b, décision du chef du 21/11/2026) : la règle vit ICI, pour toutes les entrées
 * qui posent ROLE_STAFF_PROVIDER : l'invitation de l'équipe (`AboStaffService::invite`) et l'écran Utilisateurs d'EasyAdmin
 * (`UserCrudController`). Refus : un administrateur (il a déjà toutes les dates) ; un compte client (abonnement, commande sur la
 * boutique web ou mobile, ou appartenance à une application de clients) : un intervenant a un compte à lui.
 */
class AboStaffEligibility
{
    public const ADMIN_ROLES = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /**
     * Le refus pour un compte qui porterait ces rôles avec ROLE_STAFF_PROVIDER, ou null s'il peut être intervenant.
     *
     * @param list<string> $roles les rôles qu'aurait le compte
     */
    public function refusal(?int $userId, array $roles): ?AboException
    {
        if (array_intersect(self::ADMIN_ROLES, $roles) !== []) {
            return new AboException(409, 'STAFF_IS_ADMIN', 'Ce compte administre le site : il a déjà accès à toutes les dates.', 'email');
        }
        if ($userId !== null && $this->isCustomer($userId)) {
            return new AboException(409, 'STAFF_IS_CUSTOMER', 'Ce compte est celui d\'un client (abonnement ou commande) : un intervenant a un compte à lui.', 'email');
        }

        return null;
    }

    /**
     * Les rôles à enregistrer depuis un écran qui pose les rôles à la main : ROLE_STAFF_PROVIDER retiré s'il vient d'être gagné (ou si un
     * rôle d'administration vient de l'être) par un compte qui ne peut pas être intervenant ; le refus est rendu pour être montré.
     *
     * @param list<string> $roles rôles demandés
     * @param list<string> $previous rôles relus en base avant l'enregistrement
     * @return array{0: list<string>, 1: ?AboException}
     */
    public function filterRoles(?int $userId, array $roles, array $previous): array
    {
        if (!in_array(TeamRoles::STAFF_PROVIDER, $roles, true)) {
            return [$roles, null];
        }
        $gained = !in_array(TeamRoles::STAFF_PROVIDER, $previous, true) || array_diff(array_intersect(self::ADMIN_ROLES, $roles), $previous) !== [];
        $refusal = $gained ? $this->refusal($userId, $roles) : null;

        return $refusal === null ? [$roles, null] : [array_values(array_diff($roles, [TeamRoles::STAFF_PROVIDER])), $refusal];
    }

    /**
     * Un client : un compte qui a souscrit, commandé sur la boutique web ou mobile (jamais une vente en caisse, où le compte est le
     * caissier), ou qui appartient à une application de clients (boutique, Living Memory). Relecture neuve : le rôle ROLE_USER_INTERNET ne
     * le dit pas (tout intervenant le porte).
     */
    private function isCustomer(int $userId): bool
    {
        return $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT 1 FROM subscription WHERE user_id = ?
            UNION ALL SELECT 1 FROM "order" o JOIN order_source s ON s.id = o.order_source_id WHERE o.user_id_id = ? AND s.name IN (\'Ecommerce\', \'Mobile_app\')
            UNION ALL SELECT 1 FROM user_app_membership WHERE user_id = ? AND app IN (\'boutique\', \'livingmemory\') LIMIT 1', [$userId, $userId, $userId]) !== false;
    }
}
