<?php
namespace App\Dto;

class FournisseurInputDTO
{
    public ?int $id;
    public ?string $name;
    public ?int $typeFournisseur;
    public ?string $adresse;
    public ?string $ville;
    public ?string $pays;
    public ?string $tel;

    public function __construct(array $data)
    {
        $this->id = $data['id'] ?? null;
        $this->name = $data['name'] ?? null;
        $this->typeFournisseur = $data['typeFournisseur'] ?? null;
        $this->adresse = $data['adresse'] ?? null;
        $this->ville = $data['ville'] ?? null;
        $this->pays = $data['pays'] ?? null;
        $this->tel = $data['tel'] ?? null;
    }
}
