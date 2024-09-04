<?php
namespace App\Services\FournisseurService;

use App\Dto\FournisseurInputDTO;
use App\Entity\Fournisseur;
use Doctrine\ORM\EntityManagerInterface;

class FournisseurService
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function createFournisseur(FournisseurInputDTO $inputDTO): Fournisseur
    {
        $fournisseur = new Fournisseur();
        $fournisseur->setName($inputDTO->name);
        $fournisseur->setPhoto($inputDTO->photo);
        $fournisseur->setAdresse($inputDTO->adresse);
        $fournisseur->setVille($inputDTO->ville);
        $fournisseur->setPays($inputDTO->pays);
        $fournisseur->setTel($inputDTO->tel);

        $this->entityManager->persist($fournisseur);
        $this->entityManager->flush();

        return $fournisseur;
    }

    public function getAllFournisseurs(): array
    {
        return $this->entityManager->getRepository(Fournisseur::class)->findAll();
    }

    public function deleteFournisseur(Fournisseur $fournisseur): void
    {
        $this->entityManager->remove($fournisseur);
        $this->entityManager->flush();
    }
}
