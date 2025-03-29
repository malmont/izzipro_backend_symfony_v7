<?php

namespace App\Services\CollectionService;

use App\Dto\CollectionInputDTO;
use App\Dto\CollectionOutputDTO;
use App\Entity\Collections;
use App\Entity\CollectionPicture;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\ResultSetMapping;

class CollectionService
{
    private EntityManagerInterface $entityManager;
    private UserRepository $userRepository;

    public function __construct(EntityManagerInterface $entityManager, UserRepository $userRepository)
    {
        $this->entityManager = $entityManager;
        $this->userRepository = $userRepository;
    }

    public function createCollection(CollectionInputDTO $inputDTO): Collections
    {
        $user = $this->userRepository->find($inputDTO->userId);
        if (!$user) {
            throw new \Exception('User not found');
        }

        $collection = new Collections();
        $collection->setBudgetCollection($inputDTO->budgetCollection);
        $collection->setStartDateCollection($inputDTO->startDateCollection);
        $collection->setEndDateCollection($inputDTO->endDateCollection);
        $collection->setDel($inputDTO->del);
        $collection->setNomCollection($inputDTO->nomCollection);


        $randomPicture = $this->getRandomCollectionPicture();
        $collection->setPhotoCollections($randomPicture);

        $collection->setUserCollections($user);

        $this->entityManager->persist($collection);
        $this->entityManager->flush();

        return $collection;
    }

    public function getCollections(string $host): array
    {
        $collections = $this->entityManager->getRepository(Collections::class)->findAll();
        return array_map(fn($collection) => new CollectionOutputDTO($collection, $host), $collections);
    }

    public function deleteCollection(Collections $collection): void
    {
        $this->entityManager->remove($collection);
        $this->entityManager->flush();
    }
    
    /**
     * Sélectionne aléatoirement une CollectionPicture depuis la base de données.
     * Cette méthode utilise une requête native PostgreSQL qui trie les enregistrements par RANDOM().
     */
    private function getRandomCollectionPicture(): ?CollectionPicture
    {
        $sql = 'SELECT * FROM collection_picture ORDER BY RANDOM() LIMIT 1';
        $rsm = new ResultSetMapping();
        $rsm->addEntityResult(CollectionPicture::class, 'cp');
        $rsm->addFieldResult('cp', 'id', 'id');
        // Assurez-vous que le nom de la colonne dans votre table est bien « image_url ».
        $rsm->addFieldResult('cp', 'image_url', 'imageUrl');

        return $this->entityManager->createNativeQuery($sql, $rsm)
            ->getOneOrNullResult();
    }
}
