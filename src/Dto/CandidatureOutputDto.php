<?php
namespace App\Dto;

use App\Entity\Candidature;

class CandidatureOutputDto
{
    public int $id;
    public string $nomComplet;
    public string $courriel;
    public ?string $tel;
    public ?string $lienCv;
    public ?array $emploi = null;

    public function __construct(Candidature $candidature)
    {
        $this->id = $candidature->getId();
        $this->nomComplet = $candidature->getNomComplet();
        $this->courriel = $candidature->getCourriel();
        $this->tel = $candidature->getTel();
        $this->lienCv = $candidature->getLienCv();

        if ($candidature->getEmploi()) {
            $this->emploi = [
                'id' => $candidature->getEmploi()->getId(),
                'titre' => $candidature->getEmploi()->getTitre(),
            ];
        }
    }
}