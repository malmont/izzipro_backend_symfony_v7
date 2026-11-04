<?php

namespace App\Services\Platform;

use App\Entity\Entreprise;
use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Après une écriture manuelle de la fiche Entreprise (EasyAdmin ou PUT /api/entreprise par le super administrateur), aligne les
 * droits d'usage sur les indicateurs : vrai sans droit → droit `manual` ; faux avec droit → droit annulé (lot 3, 04/11/2026).
 * Jamais d'exception vers l'appelant : la fiche est écrite, l'alignement est journalisé en cas d'échec.
 */
class EntitlementFlagSync
{
    public function __construct(private readonly EntitlementService $entitlements, private readonly TenantConnectionManager $tenants, private readonly TenantEntityManagerProvider $emProvider, private readonly Security $security, private readonly LoggerInterface $logger)
    {
    }

    public function afterEntrepriseId(int $id): void
    {
        $entreprise = $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->find($id);
        if ($entreprise instanceof Entreprise) {
            $this->afterWrite($entreprise);
        }
    }

    public function afterWrite(Entreprise $entreprise): void
    {
        $code = $this->tenants->getCurrentTenantCode();
        if ($code === null || $code === '') {
            return;
        }
        $flags = [];
        foreach (FeatureCatalog::GOVERNED_GETTERS as $key => $getter) {
            $flags[$key] = (bool) $entreprise->{$getter}();
        }
        try {
            $result = $this->entitlements->syncFromFlags($code, $flags, $this->security->getUser()?->getUserIdentifier());
            if ($result['granted'] !== [] || $result['cancelled'] !== []) {
                $this->logger->notice(sprintf('[Entitlements] %s : alignement depuis la fiche Entreprise, accordés %s, annulés %s', $code, implode(',', $result['granted']) ?: '-', implode(',', $result['cancelled']) ?: '-'));
            }
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Entitlements] %s : alignement depuis la fiche Entreprise impossible : %s', $code, $e->getMessage()));
        }
    }
}
