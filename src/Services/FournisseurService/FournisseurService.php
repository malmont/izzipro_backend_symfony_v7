<?php
namespace App\Services\FournisseurService;

use App\Dto\FournisseurInputDTO;
use App\Entity\Fournisseur;
use App\Entity\TypeFournisseur;
use App\Services\TenantEntityManagerProvider; 

class FournisseurService
{
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function createFournisseur(FournisseurInputDTO $inputDTO): Fournisseur
    {
        $em = $this->emProvider->getEntityManager();
        $typeFournisseurRepository = $em->getRepository(TypeFournisseur::class);

        $fournisseur = new Fournisseur();
        $fournisseur->setName($inputDTO->name);
        
        $typeFournisseur = $typeFournisseurRepository->find($inputDTO->typeFournisseur);
        if (!$typeFournisseur) {
            throw new \Exception('TypeFournisseur not found');
        }
        $fournisseur->setTypeFournisseur($typeFournisseur);

        $fournisseur->setAdresse($inputDTO->adresse);
        $fournisseur->setVille($inputDTO->ville);
        $fournisseur->setPays($inputDTO->pays);
        $fournisseur->setTel($inputDTO->tel);
        
        $em->persist($fournisseur);
        $em->flush();

        return $fournisseur;
    }

    public function getAllFournisseurs(): array
    {
        $em = $this->emProvider->getEntityManager();
        return $em->getRepository(Fournisseur::class)->findAll();
    }

    public function deleteFournisseur(Fournisseur $fournisseur): void
    {
        $em = $this->emProvider->getEntityManager();
        $em->remove($fournisseur);
        
        $em->flush();
    }
}