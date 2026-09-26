<?php

namespace App\MemoiresVivantes\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Rôle de contributeur autorisé pour un type de livre collectif.
 * Le code correspond à mv_contributor.role et mv_question.role.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mv_book_type_role')]
#[ORM\UniqueConstraint(name: 'uniq_mv_book_type_role_code', columns: ['book_type_id', 'code'])]
class BookTypeRole
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BookType::class, inversedBy: 'roles')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?BookType $bookType = null;

    #[ORM\Column(length: 50)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $displayOrder = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBookType(): ?BookType
    {
        return $this->bookType;
    }

    public function setBookType(?BookType $bookType): self
    {
        $this->bookType = $bookType;
        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;
        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;
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
}
