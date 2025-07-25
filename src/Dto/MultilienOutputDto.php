<?php
namespace App\Dto;

use App\Entity\Multilien;

class MultilienOutputDto
{
    public int $id;
    public string $titre;
    public string $lien;
    public ?string $imageDeFondUrl;

    public function __construct(Multilien $multilien, string $baseImageUrl)
    {
        $this->id = $multilien->getId();
        $this->titre = $multilien->getTitre();
        $this->lien = $multilien->getLien();
        $this->imageDeFondUrl = $multilien->getImageDeFond()
            ? rtrim($baseImageUrl, '/') . '/' . $multilien->getImageDeFond()
            : null;
    }
}