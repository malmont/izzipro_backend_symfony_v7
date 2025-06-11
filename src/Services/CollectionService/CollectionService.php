<?php

namespace App\Services\CollectionService;

use App\Dto\CollectionInputDTO;
use App\Dto\CollectionOutputDTO;
use App\Entity\Collections;
use App\Entity\CollectionPicture;
use App\Entity\User; // <-- On importe l'entité User
use App\Services\TenantEntityManagerProvider; // <-- On importe notre provider
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\ResultSetMapping;

class CollectionService
{
    // MODIFICATION 1 : On ne garde qu'une seule dépendance, notre provider.
    private TenantEntityManagerProvider $emProvider;

    // MODIFICATION 2 : Le constructeur n'injecte plus que le provider.
    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function createCollection(CollectionInputDTO $inputDTO): Collections
    {
        // MODIFICATION 3 : On récupère l'EntityManager du tenant au début de la méthode.
        $em = $this->emProvider->getEntityManager();

        // On récupère le UserRepository à partir de l'EM du tenant.
        $userRepository = $em->getRepository(User::class);
        $user = $userRepository->find($inputDTO->userId);
        if (!$user) {
            throw new \Exception('User not found');
        }

        $collection = new Collections();
        $collection->setBudgetCollection($inputDTO->budgetCollection);
        $collection->setStartDateCollection($inputDTO->startDateCollection);
        $collection->setEndDateCollection($inputDTO->endDateCollection);
        $collection->setDel($inputDTO->del);
        $collection->setNomCollection($inputDTO->nomCollection);
        
        // On passe l'EM du tenant à la méthode privée.
        $randomPicture = $this->getRandomCollectionPicture($em);
        $collection->setPhotoCollections($randomPicture);
        
        $collection->setUserCollections($user);

        // On utilise l'EM du tenant pour persister les données.
        $em->persist($collection);
        $em->flush();

        return $collection;
    }

    public function getCollections(string $host): array
    {
        // On récupère l'EntityManager du tenant.
        $em = $this->emProvider->getEntityManager();

        // On utilise l'EM du tenant pour obtenir le repository et les données.
        $collections = $em->getRepository(Collections::class)->findAll();
        
        return array_map(fn($collection) => new CollectionOutputDTO($collection, $host), $collections);
    }

    public function deleteCollection(Collections $collection): void
    {
        // On récupère l'EntityManager du tenant.
        $em = $this->emProvider->getEntityManager();

        // On utilise l'EM du tenant pour supprimer.
        $em->remove($collection);
        $em->flush();
    }
    
    /**
     * MODIFICATION 4 : La méthode privée reçoit maintenant l'EntityManager en paramètre
     * pour s'assurer qu'elle utilise bien la connexion du tenant.
     */
    private function getRandomCollectionPicture(EntityManagerInterface $em): ?CollectionPicture
    {
        // Note: cette requête suppose que la table `collection_picture` existe dans chaque BDD de tenant.
        $sql = 'SELECT * FROM collection_picture ORDER BY RANDOM() LIMIT 1';
        $rsm = new ResultSetMapping();
        $rsm->addEntityResult(CollectionPicture::class, 'cp');
        $rsm->addFieldResult('cp', 'id', 'id');
        $rsm->addFieldResult('cp', 'image_url', 'imageUrl');

        // On utilise l'EM qui a été passé en argument.
        return $em->createNativeQuery($sql, $rsm)
            ->getOneOrNullResult();
    }
}