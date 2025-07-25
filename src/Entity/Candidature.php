<?php

namespace App\Entity;

use App\Repository\CandidatureRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CandidatureRepository::class)]
class Candidature
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nomComplet = null;

    #[ORM\Column(length: 255)]
    private ?string $courriel = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $tel = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ville = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $codePostal = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $anneeExperience = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTime $dateDisponibilite = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $questionCommentaire = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $niveauAnglais = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $succursale = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lienCv = null;

    #[ORM\ManyToOne(inversedBy: 'candidatures')]
    private ?Emploi $emploi = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNomComplet(): ?string
    {
        return $this->nomComplet;
    }

    public function setNomComplet(string $nomComplet): static
    {
        $this->nomComplet = $nomComplet;

        return $this;
    }

    public function getCourriel(): ?string
    {
        return $this->courriel;
    }

    public function setCourriel(string $courriel): static
    {
        $this->courriel = $courriel;

        return $this;
    }

    public function getTel(): ?string
    {
        return $this->tel;
    }

    public function setTel(?string $tel): static
    {
        $this->tel = $tel;

        return $this;
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): static
    {
        $this->adresse = $adresse;

        return $this;
    }

    public function getVille(): ?string
    {
        return $this->ville;
    }

    public function setVille(?string $ville): static
    {
        $this->ville = $ville;

        return $this;
    }

    public function getCodePostal(): ?string
    {
        return $this->codePostal;
    }

    public function setCodePostal(?string $codePostal): static
    {
        $this->codePostal = $codePostal;

        return $this;
    }

    public function getAnneeExperience(): ?string
    {
        return $this->anneeExperience;
    }

    public function setAnneeExperience(?string $anneeExperience): static
    {
        $this->anneeExperience = $anneeExperience;

        return $this;
    }

    public function getDateDisponibilite(): ?\DateTime
    {
        return $this->dateDisponibilite;
    }

    public function setDateDisponibilite(?\DateTime $dateDisponibilite): static
    {
        $this->dateDisponibilite = $dateDisponibilite;

        return $this;
    }

    public function getQuestionCommentaire(): ?string
    {
        return $this->questionCommentaire;
    }

    public function setQuestionCommentaire(?string $questionCommentaire): static
    {
        $this->questionCommentaire = $questionCommentaire;

        return $this;
    }

    public function getNiveauAnglais(): ?string
    {
        return $this->niveauAnglais;
    }

    public function setNiveauAnglais(?string $niveauAnglais): static
    {
        $this->niveauAnglais = $niveauAnglais;

        return $this;
    }

    public function getSuccursale(): ?string
    {
        return $this->succursale;
    }

    public function setSuccursale(?string $succursale): static
    {
        $this->succursale = $succursale;

        return $this;
    }

    public function getLienCv(): ?string
    {
        return $this->lienCv;
    }

    public function setLienCv(?string $lienCv): static
    {
        $this->lienCv = $lienCv;

        return $this;
    }

    public function getEmploi(): ?Emploi
    {
        return $this->emploi;
    }

    public function setEmploi(?Emploi $emploi): static
    {
        $this->emploi = $emploi;

        return $this;
    }
}
