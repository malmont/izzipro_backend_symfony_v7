<?php

namespace App\ESG\Entity;

use App\ESG\Enum\DomainEnum;
use App\ESG\Repository\OddMappingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OddMappingRepository::class)]
#[ORM\Table(name: 'esg_odd_mapping')]
#[ORM\UniqueConstraint(name: 'uniq_referential_odd_domain', columns: ['referential_id', 'odd_number', 'domain'])]
class OddMapping
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'odd_number', type: 'integer')]
    private int $oddNumber = 1;

    #[ORM\Column(length: 255)]
    private ?string $oddLabel = null;

    #[ORM\Column(type: 'string', length: 50, enumType: DomainEnum::class, nullable: true)]
    private ?DomainEnum $domain = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $justification = null;

    #[ORM\Column(name: 'display_order', type: 'integer')]
    private int $displayOrder = 0;

    #[ORM\ManyToOne(targetEntity: CertificationReferential::class, inversedBy: 'oddMappings')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CertificationReferential $referential = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOddNumber(): int
    {
        return $this->oddNumber;
    }

    public function setOddNumber(int $oddNumber): self
    {
        $this->oddNumber = $oddNumber;
        return $this;
    }

    public function getOddLabel(): ?string
    {
        return $this->oddLabel;
    }

    public function setOddLabel(string $oddLabel): self
    {
        $this->oddLabel = $oddLabel;
        return $this;
    }

    public function getDomain(): ?DomainEnum
    {
        return $this->domain;
    }

    public function setDomain(?DomainEnum $domain): self
    {
        $this->domain = $domain;
        return $this;
    }

    public function getJustification(): ?string
    {
        return $this->justification;
    }

    public function setJustification(?string $justification): self
    {
        $this->justification = $justification;
        return $this;
    }

    public function getDisplayOrder(): int
    {
        return $this->displayOrder;
    }

    public function setDisplayOrder(int $displayOrder): self
    {
        $this->displayOrder = $displayOrder;
        return $this;
    }

    public function getReferential(): ?CertificationReferential
    {
        return $this->referential;
    }

    public function setReferential(?CertificationReferential $referential): self
    {
        $this->referential = $referential;
        return $this;
    }
}
