<?php

namespace App\Services\ServiceFamilyService;

use App\Dto\ServiceFamilyInputDto;
use App\Entity\ServiceFamily;
use App\Entity\ServiceFamilyTranslation;
use App\Services\TenantEntityManagerProvider;

/** Familles de prestations (28/10/2026) : ordre par position puis identifiant */
final class ServiceFamilyService
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /** @return list<ServiceFamily> */
    public function all(): array
    {
        return $this->emProvider->getEntityManager()->getRepository(ServiceFamily::class)->findBy([], ['position' => 'ASC', 'id' => 'ASC']);
    }

    public function find(int $id): ?ServiceFamily
    {
        return $this->emProvider->getEntityManager()->getRepository(ServiceFamily::class)->find($id);
    }

    public function save(ServiceFamily $family, ServiceFamilyInputDto $dto): ServiceFamily
    {
        if ($dto->name !== null) {
            $family->setName($dto->name);
        }
        if ($dto->position !== null) {
            $family->setPosition($dto->position);
        }
        if ($dto->translations !== null) {
            foreach ($family->getTranslations()->toArray() as $old) {
                $family->removeTranslation($old);
            }
            foreach ($dto->translations as $translation) {
                $family->addTranslation((new ServiceFamilyTranslation())->setLanguage($translation['language'])->setName($translation['name']));
            }
        }
        $em = $this->emProvider->getEntityManager();
        $em->persist($family);
        $em->flush();

        return $family;
    }

    /** Les prestations de la famille la perdent (family_id ON DELETE SET NULL) */
    public function delete(ServiceFamily $family): void
    {
        $em = $this->emProvider->getEntityManager();
        $em->remove($family);
        $em->flush();
    }
}
