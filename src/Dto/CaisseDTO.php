<?php
namespace App\Dto;

use App\Entity\Caisse;

/**
 * Session de caisse (GET /api/caisse). Champs historiques inchangés ; ajoutés le 15/10/2026 : cashRegister {id, name}
 * et closedAt (null tant que la session est ouverte).
 */
class CaisseDTO
{
    /** @param list<TransationCaisseDTO> $transactionCaisses */
    public function __construct(
        private int $id,
        private ?float $amountTotal,
        private ?float $fonDeCaisse,
        private ?string $createdAt,
        private ?bool $isOpen,
        private array $transactionCaisses,
        private ?array $cashRegister = null,
        private ?string $closedAt = null
    ) {
    }

    /** @param list<TransationCaisseDTO> $transactions */
    public static function fromEntity(Caisse $caisse, array $transactions): self
    {
        $register = $caisse->getCashRegister();

        return new self(
            $caisse->getId(),
            $caisse->getAmountTotal(),
            $caisse->getFonDeCaisse(),
            $caisse->getCreatedAt()?->format('Y-m-d H:i:s'),
            $caisse->isOpen(),
            $transactions,
            $register ? ['id' => $register->getId(), 'name' => $register->getName()] : null,
            $caisse->getClosedAt()?->format('Y-m-d H:i:s')
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'amountTotal' => $this->amountTotal,
            'fonDeCaisse' => $this->fonDeCaisse,
            'createdAt' => $this->createdAt,
            'isOpen' => $this->isOpen,
            'transactionCaisses' => array_map(fn($item) => $item->toArray(), $this->transactionCaisses),
            'cashRegister' => $this->cashRegister,
            'closedAt' => $this->closedAt,
        ];
    }
}
