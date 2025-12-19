<?php
namespace App\Services\NoteDeFraisService;

use App\Entity\Collections;
use App\Entity\NoteDeFrais;
use App\Entity\TypeNoteDeFrais;
use App\Dto\NoteDeFraisInputDTO;
use App\Services\TenantEntityManagerProvider;

class NoteDeFraisService
{
    // MODIFICATION 1 : Le service ne dépend plus que du provider
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    /**
     * INCHANGÉ : Cette méthode ne touche pas à la base de données.
     */
    public function getNotesByCollection(Collections $collection): array
    {
        return $collection->getNoteDeFrais()->toArray();
    }

    public function createNoteDeFrais(Collections $collection, NoteDeFraisInputDTO $inputDTO): void
    {
        // MODIFICATION 2 : On récupère l'EM et les dépendances ici
        $em = $this->emProvider->getEntityManager();
        $typeNoteDeFraisRepository = $em->getRepository(TypeNoteDeFrais::class);

        $note = new NoteDeFrais();
        $note->setDescription($inputDTO->description);
        $note->setMontant($inputDTO->montant);
        $note->setDate(new \DateTime($inputDTO->date));
        
        $typeNoteDeFrais = $typeNoteDeFraisRepository->find($inputDTO->typeNoteDeFraisId);
        $note->setTypeNoteDeFrais($typeNoteDeFrais);
        $note->setCollection($collection);
        $em->persist($note);
        $em->flush();
    }

    public function updateNoteDeFrais(NoteDeFrais $note, NoteDeFraisInputDTO $inputDTO): void
    {
        $em = $this->emProvider->getEntityManager();
        $typeNoteDeFraisRepository = $em->getRepository(TypeNoteDeFrais::class);
        $note->setDescription($inputDTO->description);
        $note->setMontant($inputDTO->montant);
        $note->setDate(new \DateTime($inputDTO->date));
        $typeNoteDeFrais = $typeNoteDeFraisRepository->find($inputDTO->typeNoteDeFraisId);
        $note->setTypeNoteDeFrais($typeNoteDeFrais);

 
        $em->flush();
    }

    public function deleteNoteDeFrais(NoteDeFrais $note): void
    {
        $em = $this->emProvider->getEntityManager();
        $em->remove($note);
        $em->flush();
    }
}