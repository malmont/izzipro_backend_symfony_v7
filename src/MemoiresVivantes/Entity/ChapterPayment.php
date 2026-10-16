<?php

namespace App\MemoiresVivantes\Entity;

use App\Entity\User;
use App\MemoiresVivantes\Repository\ChapterPaymentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Achat intégré d'une rédaction de chapitre (16/10/2026), tel que rapporté par RevenueCat (Apple ou Google) :
 * registre des paiements de l'application mobile. Une transaction de store = une ligne, affectée à un chapitre
 * (chapterId) au moment du déblocage ; non affectée tant que l'utilisateur n'a pas choisi le chapitre. Un
 * remboursement (webhook) passe la ligne en « refunded » et masque le chapitre. Le chapitre porte en copie le
 * paiement qui le débloque (mv_chapter.paid_*), ce registre garde l'historique.
 */
#[ORM\Entity(repositoryClass: ChapterPaymentRepository::class)]
#[ORM\Table(name: 'mv_chapter_payment')]
#[ORM\UniqueConstraint(name: 'uniq_mv_chapter_payment_tx', columns: ['store', 'transaction_id'])]
#[ORM\Index(columns: ['user_id'], name: 'idx_mv_chapter_payment_user')]
class ChapterPayment
{
    public const STORE_APP_STORE = 'app_store';
    public const STORE_PLAY_STORE = 'play_store';
    public const STORES = [self::STORE_APP_STORE, self::STORE_PLAY_STORE];
    public const STATUS_PAID = 'paid';
    public const STATUS_REFUNDED = 'refunded';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $bookId = null;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $chapterId = null;

    #[ORM\Column(length: 20)]
    private string $store;

    #[ORM\Column(length: 100)]
    private string $productId;

    #[ORM\Column(length: 191)]
    private string $transactionId;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $amount = null;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $currency = null;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_PAID])]
    private string $status = self::STATUS_PAID;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $purchasedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $assignedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $refundedAt = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $refundedAmount = null;

    /** Environnement du store rapporté par RevenueCat : sandbox (achat de test) ou production ; null si inconnu */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $environment = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $store, string $productId, string $transactionId)
    {
        if (!in_array($store, self::STORES, true)) {
            throw new \InvalidArgumentException(sprintf('Store inconnu : « %s »', $store));
        }
        $this->user = $user;
        $this->store = $store;
        $this->productId = $productId;
        $this->transactionId = $transactionId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getBookId(): ?Uuid { return $this->bookId; }
    public function setBookId(?Uuid $bookId): static { $this->bookId = $bookId; return $this; }
    public function getChapterId(): ?Uuid { return $this->chapterId; }
    public function getStore(): string { return $this->store; }
    public function getProductId(): string { return $this->productId; }
    public function getTransactionId(): string { return $this->transactionId; }
    public function getAmount(): ?float { return $this->amount === null ? null : (float) $this->amount; }
    public function setAmount(?float $amount): static { $this->amount = $amount === null ? null : number_format($amount, 2, '.', ''); return $this; }
    public function getCurrency(): ?string { return $this->currency; }
    public function setCurrency(?string $currency): static { $this->currency = $currency === null ? null : strtolower(substr($currency, 0, 3)); return $this; }
    public function getStatus(): string { return $this->status; }
    public function isRefunded(): bool { return $this->status === self::STATUS_REFUNDED; }
    public function getPurchasedAt(): ?\DateTimeImmutable { return $this->purchasedAt; }
    public function setPurchasedAt(?\DateTimeImmutable $at): static { $this->purchasedAt = $at; return $this; }
    public function getAssignedAt(): ?\DateTimeImmutable { return $this->assignedAt; }
    public function getRefundedAt(): ?\DateTimeImmutable { return $this->refundedAt; }
    public function getRefundedAmount(): ?float { return $this->refundedAmount === null ? null : (float) $this->refundedAmount; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getEnvironment(): ?string { return $this->environment; }
    public function setEnvironment(?string $environment): static { $this->environment = $environment === null ? null : strtolower($environment); return $this; }

    /** Référence affichée sur le chapitre et dans la console : store et transaction */
    public function reference(): string
    {
        return $this->store . ':' . $this->transactionId;
    }

    public function isAssigned(): bool
    {
        return $this->chapterId !== null;
    }

    public function assignTo(Chapter $chapter): static
    {
        $this->chapterId = $chapter->getId();
        $this->bookId = $chapter->getBook()?->getId();
        $this->assignedAt = new \DateTimeImmutable();

        return $this;
    }

    /** Remboursement total (Apple et Google remboursent un consommable en entier) */
    public function markRefunded(?float $amount = null): static
    {
        $this->status = self::STATUS_REFUNDED;
        $this->refundedAt = new \DateTimeImmutable();
        $this->refundedAmount = number_format($amount ?? $this->getAmount() ?? 0.0, 2, '.', '');

        return $this;
    }
}
