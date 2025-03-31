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

    public function __construct(Fournisseur $fournisseur, string $host)
    {
        $this->id = $fournisseur->getId();
        $this->name = $fournisseur->getName();
        $image = $fournisseur->getTypeFournisseur();
        $this->photo = $image ? $host . '/assets/images/' . $image->getPhoto() : null;
        $this->adresse = $fournisseur->getAdresse();
        $this->ville = $fournisseur->getVille();
        $this->pays = $fournisseur->getPays();
        $this->tel = $fournisseur->getTel();
    }
}
