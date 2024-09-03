<?php

namespace App\Services;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EntityRetrieverService
{
    private $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
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
        $entity = $this->em->getRepository($entityClass)->find($id);
        if (!$entity) {
            throw new NotFoundHttpException($errorMessage);
        }

        return $entity;
    }
}
