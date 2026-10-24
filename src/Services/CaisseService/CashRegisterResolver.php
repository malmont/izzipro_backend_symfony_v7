<?php

namespace App\Services\CaisseService;

use App\Entity\Caisse;
use App\Entity\CashRegister;
use App\Services\TenantEntityManagerProvider;

/**
 * Choix du poste de caisse d'une opération (règle 3.2 du 15/10/2026) :
 * - cashRegisterId fourni → il doit exister et être actif, sinon 404 CASH_REGISTER_NOT_FOUND ;
 * - absent → si le site a exactement un poste actif, on le prend (comportement d'avant les postes multiples), sinon
 *   400 CASH_REGISTER_REQUIRED (le cas n'existe que lorsqu'un administrateur a créé un deuxième poste).
 */
class CashRegisterResolver
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly \App\Services\PosAuditService\PosTerminalService $terminals, private readonly \Symfony\Bundle\SecurityBundle\Security $security)
    {
    }

    public function resolve(?int $cashRegisterId): CashRegister
    {
        $repo = $this->emProvider->getEntityManager()->getRepository(CashRegister::class);
        // Terminal rattaché à un poste (clé d'activation, S2 30/10/2026) : un caissier n'agit que sur ce poste ; sans cashRegisterId, c'est lui ;
        // le responsable et l'administrateur gardent tous les postes. Sans rattachement : comportement d'avant.
        $bound = $this->terminals->current()['cashRegisterId'] ?? null;
        $user = $this->security->getUser();
        if ($bound !== null && $user instanceof \App\Entity\User && !\App\Services\PosAuditService\PosApprovalService::isManager($user)) {
            if ($cashRegisterId !== null && $cashRegisterId !== $bound) {
                throw new CashRegisterException('CASH_REGISTER_FORBIDDEN', 'Ce terminal est rattaché à un autre poste de caisse', 403, [], ['cashRegisterId' => $cashRegisterId, 'terminalCashRegisterId' => $bound]);
            }
            $cashRegisterId = $bound;
        }
        if ($cashRegisterId !== null) {
            return $repo->findOneActive($cashRegisterId)
                ?? throw new CashRegisterException('CASH_REGISTER_NOT_FOUND', 'Poste de caisse introuvable ou inactif', 404);
        }
        $active = $repo->findAllOrdered(true);
        if (count($active) === 1) {
            return $active[0];
        }

        throw new CashRegisterException('CASH_REGISTER_REQUIRED', $active
            ? 'Plusieurs postes de caisse : indiquez cashRegisterId'
            : 'Aucun poste de caisse actif sur ce site', 400);
    }

    public function openSession(CashRegister $register): ?Caisse
    {
        return $this->emProvider->getEntityManager()->getRepository(Caisse::class)->findOpenSession($register);
    }

    /** Session ouverte du poste choisi ; sans session ouverte : CASH_REGISTER_CLOSED avec le code HTTP donné (409 pour une vente) */
    public function requireOpenSession(?int $cashRegisterId, int $closedStatus = 400): Caisse
    {
        $register = $this->resolve($cashRegisterId);

        return $this->openSession($register)
            ?? throw new CashRegisterException('CASH_REGISTER_CLOSED', sprintf('Aucune session ouverte sur le poste « %s »', $register->getName()), $closedStatus);
    }
}
