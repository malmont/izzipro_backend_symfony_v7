<?php
namespace App\Services\NoteDeFraisService;

use App\Entity\Collections;
use App\Entity\NoteDeFrais;
use App\Entity\TypeNoteDeFrais;
use App\Dto\NoteDeFraisInputDTO;
use Doctrine\ORM\EntityManagerInterface;

class NoteDeFraisService
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function getNotesByCollection(Collections $collection): array
    {
        return $collection->getNoteDeFrais()->toArray();
    }

    public function createNoteDeFrais(Collections $collection, NoteDeFraisInputDTO $inputDTO): void
    {
        $note = new NoteDeFrais();
        $note->setDescription($inputDTO->description);
        $note->setMontant($inputDTO->montant);
        $note->setDate(new \DateTime($inputDTO->date));
        $typeNoteDeFrais = $this->entityManager->getRepository(TypeNoteDeFrais::class)->find($inputDTO->typeNoteDeFraisId);
        $note->setTypeNoteDeFrais($typeNoteDeFrais);
        $note->setCollection($collection);

        $this->entityManager->persist($note);
        $this->entityManager->flush();
    }

    public function updateNoteDeFrais(NoteDeFrais $note, NoteDeFraisInputDTO $inputDTO): void
    {
        $note->setDescription($inputDTO->description);
        $note->setMontant($inputDTO->montant);
        $note->setDate(new \DateTime($inputDTO->date));
        $typeNoteDeFrais = $this->entityManager->getRepository(TypeNoteDeFrais::class)->find($inputDTO->typeNoteDeFraisId);
        $note->setTypeNoteDeFrais($typeNoteDeFrais);
        $this->entityManager->flush();
    }

    public function deleteNoteDeFrais(NoteDeFrais $note): void
    {
        $this->entityManager->remove($note);
        $this->entityManager->flush();
    }
}
