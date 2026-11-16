<?php

namespace App\LossPrevention\Entity;

use App\LossPrevention\Repository\LossSaleUnitRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un article SUIVI vendu à la caisse (lot L3-b2a, `docs/prevention-pertes.md` § 16.18) : la vente (commande, ligne, rang dans la
 * ligne), sa variante, son poste et son heure réelle, et la PIÈCE qui lui est reliée quand elle a été lue (sur le tapis du poste,
 * ou à la sortie par rattrapage). L'analyse du module l'écrit en lisant le journal de caisse : la caisse n'en sait rien. La base
 * garde elle-même qu'un article n'a qu'une pièce et qu'une pièce n'est vendue qu'une fois à la fois (migration du lot). Lu et écrit
 * en SQL par `LossSaleUnitRepository`.
 */
#[ORM\Entity(repositoryClass: LossSaleUnitRepository::class)]
#[ORM\Table(name: 'loss_sale_unit')]
#[ORM\UniqueConstraint(name: 'uniq_loss_sale_unit_item', columns: ['order_item_id', 'unit_no'])]
#[ORM\UniqueConstraint(name: 'uniq_loss_sale_unit_piece', columns: ['piece_id'], options: ['where' => 'piece_id IS NOT NULL AND returned_at IS NULL'])]
#[ORM\Index(name: 'idx_loss_sale_unit_open', columns: ['sold_at', 'id'], options: ['where' => 'piece_id IS NULL AND returned_at IS NULL'])]
#[ORM\Index(name: 'idx_loss_sale_unit_order', columns: ['order_id'])]
#[ORM\Index(name: 'idx_loss_sale_unit_register', columns: ['cash_register_id', 'sold_at'])]
class LossSaleUnit
{
    /** Par quoi la pièce a été reliée : lue sur le tapis du poste avant la vente, ou lue à la sortie après elle */
    public const BY_COUNTER = 'counter';
    public const BY_CATCH_UP = 'catch_up';
    public const RETURN = 'return';
    public const CANCELLATION = 'cancellation';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?string $id = null;

    #[ORM\Column(name: 'order_id')]
    private int $orderId;

    #[ORM\Column(name: 'order_item_id')]
    private int $orderItemId;

    #[ORM\Column(name: 'unit_no')]
    private int $unitNo;

    #[ORM\Column(name: 'variant_id')]
    private int $variantId;

    #[ORM\Column(name: 'cash_register_id', nullable: true)]
    private ?int $cashRegisterId = null;

    #[ORM\Column(name: 'sold_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $soldAt;

    #[ORM\Column(name: 'audit_sequence', type: 'bigint')]
    private string $auditSequence;

    #[ORM\Column(name: 'piece_id', nullable: true)]
    private ?int $pieceId = null;

    #[ORM\Column(name: 'matched_by', length: 10, nullable: true)]
    private ?string $matchedBy = null;

    #[ORM\Column(name: 'matched_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $matchedAt = null;

    #[ORM\Column(name: 'returned_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $returnedAt = null;

    #[ORM\Column(name: 'return_sequence', type: 'bigint', nullable: true)]
    private ?string $returnSequence = null;

    /** Par quoi l'article est revenu : `return` (un retour d'articles : la pièce est sortie puis revenue) ou `cancellation` (la vente a été annulée) */
    #[ORM\Column(name: 'returned_by', length: 12, nullable: true)]
    private ?string $returnedBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;
}
