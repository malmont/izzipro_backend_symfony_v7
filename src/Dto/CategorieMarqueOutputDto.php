<?php
namespace App\Dto;

use App\Entity\CategorieMarque;

class CategorieMarqueOutputDto
{
    public int $id;
    public string $nom;
    public int $nombreMarques;

    public function __construct(CategorieMarque $categorieMarque)
    {
        $this->id = $categorieMarque->getId();
        $this->nom = $categorieMarque->getNom();
        $this->nombreMarques = $categorieMarque->getMarques()->count();
    }
}
