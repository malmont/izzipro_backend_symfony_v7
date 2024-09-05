<?php
namespace App\Services\CommandeService;

use App\Entity\Collections;
use App\Entity\Commande;
use App\Entity\Fournisseur;
use Doctrine\ORM\EntityManagerInterface;
use App\Dto\FournisseurInputDTO;

class CommandeService
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function getCommandesByCollection(Collections $collection)
    {
        return $this->entityManager->getRepository(Collections::class)
            ->find($collection->getId())
            ->getCommandes();
    }

    public function createCommande(array $data, Collections $collection, Fournisseur $fournisseur): Commande
    {
        $commande = new Commande();
        $commande->setBudget($data['budget']);
        $commande->setDate(new \DateTime($data['date']));
        $commande->setName($data['name']);
        $commande->setPhoto($data['photo']);
        $commande->setCollections($collection);
        $commande->setFournisseur($fournisseur);

        $this->entityManager->persist($commande);
        $this->entityManager->flush();

        return $commande;
    }

    public function findOrCreateFournisseur(FournisseurInputDTO $fournisseurDTO): Fournisseur
    {
        $fournisseur = $this->entityManager->getRepository(Fournisseur::class)->findOneBy([
            'name' => $fournisseurDTO->name,
            'adresse' => $fournisseurDTO->adresse,
            'ville' => $fournisseurDTO->ville,
            'pays' => $fournisseurDTO->pays,
            'tel' => $fournisseurDTO->tel
        ]);

        if (!$fournisseur) {
            $fournisseur = new Fournisseur();
            $fournisseur->setName($fournisseurDTO->name);
            $fournisseur->setPhoto($fournisseurDTO->photo);
            $fournisseur->setAdresse($fournisseurDTO->adresse);
            $fournisseur->setVille($fournisseurDTO->ville);
            $fournisseur->setPays($fournisseurDTO->pays);
            $fournisseur->setTel($fournisseurDTO->tel);

            $this->entityManager->persist($fournisseur);
        }

        return $fournisseur;
    }
}
