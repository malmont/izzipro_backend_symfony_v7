<?php
namespace App\Services\CollectionService;

use App\Dto\CollectionInputDTO;
use App\Dto\CollectionOutputDTO;
use App\Entity\Collections;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

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
        $collection->setPhotoCollection($inputDTO->photoCollection);
        $collection->setUserCollections($user);

        $this->entityManager->persist($collection);
        $this->entityManager->flush();

        return $collection;
    }

    public function getCollections(): array
    {
        $collections = $this->entityManager->getRepository(Collections::class)->findAll();
        return array_map(fn($collection) => new CollectionOutputDTO($collection), $collections);
    }

    public function deleteCollection(Collections $collection): void
    {
        $this->entityManager->remove($collection);
        $this->entityManager->flush();
    }
}
