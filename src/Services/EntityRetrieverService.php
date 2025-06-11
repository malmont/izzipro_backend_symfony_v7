<?php

namespace App\Services;

use App\Services\TenantEntityManagerProvider; // Ajout
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EntityRetrieverService
{
    private TenantEntityManagerProvider $tenantEmProvider;

    public function __construct(TenantEntityManagerProvider $tenantEmProvider)
    {
        $this->tenantEmProvider = $tenantEmProvider;
    }

    /**
     * Retrieve an entity by class and ID, with error handling.
     *
     * @template T
     * @param class-string<T> $entityClass
     * @param int|string $id
     * @param string $errorMessage
     * @return T|null
     */
    public function findOrFail(string $entityClass, $id, string $errorMessage = 'Entity not found'): ?object
    {
        $em = $this->tenantEmProvider->getEntityManager();
        $entity = $em->getRepository($entityClass)->find($id);
        if (!$entity) {
            throw new NotFoundHttpException($errorMessage);
        }

        return $entity;
    }
}
