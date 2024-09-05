<?php
namespace App\Dto;

use App\Entity\Commande;

class CommandeOutputDTO
{
    public int $id;
    public float $budget;
    public string $date;
    public string $name;
    public ?string $photo;
    public int $collectionId;
    public ?FournisseurOutputDTO $fournisseur;

    public function __construct(Commande $commande)
    {
        $this->id = $commande->getId();
        $this->budget = $commande->getBudget();
        $this->date = $commande->getDate()->format('Y-m-d H:i:s');
        $this->name = $commande->getName();
        $this->photo = $commande->getPhoto();
        $this->collectionId = $commande->getCollections()->getId();
        
        $fournisseur = $commande->getFournisseur();
        $this->fournisseur = $fournisseur ? new FournisseurOutputDTO($fournisseur) : null;
    }
}
