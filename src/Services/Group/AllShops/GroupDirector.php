<?php

namespace App\Services\Group\AllShops;

use App\Entity\User;
use App\Services\FeatureAccess\ModuleAccessService;
use App\Services\Group\GroupRepository;
use App\Services\Platform\PlatformAccessJournal;
use App\Services\TenantConnectionProvider;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Lot IA-G (19/11/2026) : qui est le DIRECTEUR d'un groupe, en un seul endroit (garde de « Toutes les boutiques », outils de l'assistant).
 * Un compte du site (jamais un opérateur entré : `GroupOperator`, `ExternalOperator` ne sont pas des `User`), administrateur, qui n'est
 * pas un compte de la plateforme, sur le site du SIÈGE d'un groupe actif, module Multi-boutiques actif au siège. Tout est relu à chaque
 * appel (base maître et modules du site). Base maître ou indicateurs illisibles : personne n'est le directeur.
 */
class GroupDirector
{
    public function __construct(
        private readonly TokenStorageInterface $tokens,
        private readonly GroupRepository $groups,
        private readonly ModuleAccessService $modules,
        private readonly TenantConnectionProvider $tenants,
    ) {
    }

    /** @return array<string, mixed>|null le groupe que dirige le compte connecté, relu ; null sinon */
    public function group(): ?array
    {
        $token = $this->tokens->getToken();
        $user = $token?->getUser();
        if (!$user instanceof User || !in_array('ROLE_ADMIN', $token->getRoleNames(), true) || PlatformAccessJournal::isPlatformAccount($token->getRoleNames())) {
            return null;
        }
        $current = (string) $this->tenants->getTenantCode();
        if ($current === '') {
            return null;
        }
        try {
            $group = $this->groups->ofSite($current);
            if ($group === null || $group['head_tenant_code'] !== $current || !$this->modules->isEnabled(ModuleAccessService::MULTI_SHOP)) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        return $group;
    }
}
