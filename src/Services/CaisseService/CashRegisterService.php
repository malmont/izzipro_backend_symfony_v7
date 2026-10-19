<?php

namespace App\Services\CaisseService;

use App\Entity\CashRegister;
use App\Services\TenantCacheService;
use App\Services\TenantEntityManagerProvider;

/** Postes de caisse : liste, création, modification (jamais de suppression : historique) */
class CashRegisterService
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly CashRegisterResolver $resolver, private readonly TenantCacheService $cache, private readonly ?\App\Services\PosAuditService\PosAuditService $audit = null)
    {
    }

    /** @return list<CashRegister> */
    public function list(bool $includeInactive): array
    {
        return $this->emProvider->getEntityManager()->getRepository(CashRegister::class)->findAllOrdered(!$includeInactive);
    }

    public function find(int $id): CashRegister
    {
        return $this->emProvider->getEntityManager()->getRepository(CashRegister::class)->find($id)
            ?? throw new CashRegisterException('CASH_REGISTER_NOT_FOUND', 'Poste de caisse introuvable', 404);
    }

    public function create(string $name): CashRegister
    {
        $this->assertNameFree($name, null);
        $register = (new CashRegister())->setName($name);
        $em = $this->emProvider->getEntityManager();
        $em->persist($register);
        $em->flush();
        $this->audit?->record(\App\Services\PosAuditService\PosAuditService::CASH_REGISTER_CREATED, ['name' => $name], null, $register, 'cash_register', $register->getId());

        return $register;
    }

    public function update(CashRegister $register, ?string $name, ?bool $isActive): CashRegister
    {
        $before = ['name' => $register->getName(), 'isActive' => $register->isActive()];
        if ($name !== null) {
            $this->assertNameFree($name, $register);
            $register->setName($name);
        }
        if ($isActive !== null) {
            if (!$isActive && $this->resolver->openSession($register)) {
                throw new CashRegisterException('CASH_REGISTER_OPEN', 'Fermez la session de ce poste avant de le désactiver', 409);
            }
            $register->setIsActive($isActive);
        }
        $this->emProvider->getEntityManager()->flush();
        $this->cache->invalidateTags(['caisses_tag']); // le nom du poste figure dans les sessions (GET /api/caisse)
        $this->audit?->record(\App\Services\PosAuditService\PosAuditService::CASH_REGISTER_UPDATED,
            ['before' => $before, 'after' => ['name' => $register->getName(), 'isActive' => $register->isActive()]], null, $register, 'cash_register', $register->getId());

        return $register;
    }

    private function assertNameFree(string $name, ?CashRegister $self): void
    {
        $existing = $this->emProvider->getEntityManager()->getRepository(CashRegister::class)->findOneByName($name);
        if ($existing && $existing !== $self) {
            throw new CashRegisterException('VALIDATION_FAILED', 'Un poste porte déjà ce nom', 422, [['path' => 'name', 'message' => 'un poste porte déjà ce nom']]);
        }
    }
}
