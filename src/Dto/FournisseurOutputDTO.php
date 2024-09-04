<?php
namespace App\Dto;

use App\Entity\Fournisseur;

class FournisseurOutputDTO
{
    public int $id;
    public ?string $name;
    public ?string $photo;
    public ?string $adresse;
    public ?string $ville;
    public ?string $pays;
    public ?string $tel;

    public function __construct(Fournisseur $fournisseur)
    {
        $this->id = $fournisseur->getId();
        $this->name = $fournisseur->getName();
        $this->photo = $fournisseur->getPhoto();
        $this->adresse = $fournisseur->getAdresse();
        $this->ville = $fournisseur->getVille();
        $this->pays = $fournisseur->getPays();
        $this->tel = $fournisseur->getTel();
    }
}
