<?php

namespace App\UseCase\AppMembershipUseCase;

use App\Entity\User;
use App\Security\AppFamilies;
use App\Services\AppMembershipService\AppMembershipException;
use App\Services\AppMembershipService\AppMembershipService;
use App\Services\PosCustomerService\PosCustomerService;
use App\Services\TenantEntityManagerProvider;

/**
 * DELETE /api/account/memberships/{app} (lot APPS-MEMBRES M3) : le client quitte une famille sans supprimer son compte (retrait du
 * consentement, Loi 25). L'appartenance est retirée, au journal (`revoke`, raison `left`) ; aucune donnée n'est supprimée ; pour
 * revenir, il faudra « rejoindre ». Famille de la caisse : quitter, c'est retirer le consentement de rattachement ; la fiche de
 * caisse est détachée du compte et le retrait y est enregistré, dans la même transaction.
 */
class LeaveAppUseCase
{
    public const REASON = 'left';

    public function __construct(
        private readonly AppMembershipService $memberships,
        private readonly PosCustomerService $posCustomers,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly ListMyMembershipsUseCase $mine,
    ) {
    }

    /**
     * @return array<string, mixed> ce qu'il reste : `{ team, memberships }`
     *
     * @throws AppMembershipException 404 APP_UNKNOWN, 404 MEMBERSHIP_NOT_FOUND (un compte d'équipe n'a pas d'appartenance)
     */
    public function execute(User $user, string $app): array
    {
        $family = AppFamilies::of($app) ?? throw new AppMembershipException('APP_UNKNOWN', sprintf('Application inconnue : « %s »', $app), 404, ['params' => ['apps' => array_keys(AppFamilies::APPS), 'families' => AppFamilies::ALL]]);
        if (!$this->memberships->has($user, $family)) {
            throw new AppMembershipException('MEMBERSHIP_NOT_FOUND', 'Ce compte n\'est pas membre de cette application.', 404, ['app' => $family]);
        }
        $connection = $this->emProvider->getEntityManager()->getConnection();
        $connection->beginTransaction();
        try {
            if ($family === AppFamilies::POS) {
                $this->posCustomers->unlinkByAccount($user);
            }
            $this->memberships->revoke((int) $user->getId(), $family, self::REASON);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        return $this->mine->execute($user);
    }
}
