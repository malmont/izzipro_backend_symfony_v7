<?php

namespace App\UseCase\CommandeUseCase;

use App\Entity\Commande;
use App\Entity\CommandeLine;
use App\Entity\ProductVariant;
use App\Services\CommandeService\CommandeLineService;
use App\Services\CommandeService\CommandeProductAttachmentService;
use App\Services\GestionService\GestionConflictException;
use App\Services\GestionService\GestionInput;
use App\Services\GestionService\PeriodGuard;
use App\Services\TenantEntityManagerProvider;

/**
 * Lignes d'achat d'une commande (lot L0 de la gestion, 10/11/2026, `docs/lignes-achat-gestion.md`) : liste, modification (quantité,
 * coût, variante) et suppression d'une ligne ; écriture refusée sur une commande figée (409 COMMANDE_CLOSED) ou datée dans une période
 * fermée (409 PERIOD_CLOSED) ; 404 LINE_NOT_FOUND ; 422 VALIDATION_FAILED.
 */
class CommandeLineUseCase
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly CommandeLineService $lines, private readonly PeriodGuard $guard)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(Commande $commande): array
    {
        return array_map(fn (CommandeLine $l) => CommandeProductAttachmentService::lineArray($l), $this->emProvider->getEntityManager()->getRepository(CommandeLine::class)->forCommande($commande));
    }

    /** @param array<string, mixed> $data */
    public function update(Commande $commande, int $lineId, array $data): array
    {
        $line = $this->line($commande, $lineId);
        $input = new GestionInput($data);
        $fields = [];
        if (array_key_exists('quantity', $data)) {
            $fields['quantity'] = $input->integer('quantity');
        }
        if (array_key_exists('unitCost', $data)) {
            $fields['unitCost'] = $data['unitCost'] === null ? null : $input->cents('unitCost');
        }
        $variant = null;
        if (array_key_exists('variantId', $data)) {
            $variantId = $data['variantId'] === null ? null : $input->id('variantId');
            $input->check();
            if ($variantId !== null) {
                $variant = $this->emProvider->getEntityManager()->find(ProductVariant::class, $variantId);
                if ($variant === null || $variant->getProduct()?->getId() !== $line->getProduct()->getId()) {
                    throw new \App\Services\GestionService\GestionValidationException(['variantId' => 'VARIANT_NOT_IN_PRODUCT'], 'Variante absente de l\'article de la ligne');
                }
                $other = $this->emProvider->getEntityManager()->getRepository(CommandeLine::class)->findOne($commande, $line->getProduct(), $variant);
                if ($other !== null && $other->getId() !== $line->getId()) {
                    throw new GestionConflictException('LINE_ALREADY_EXISTS', 'Cette variante a déjà sa ligne dans la commande.', 409, ['lineId' => $other->getId()]);
                }
            }
            $fields['variant'] = $variant;
        }
        $input->check();
        $this->guard->assertCommandeWritable($commande);

        return CommandeProductAttachmentService::lineArray($this->lines->update($line, $fields));
    }

    public function delete(Commande $commande, int $lineId): void
    {
        $line = $this->line($commande, $lineId);
        $this->guard->assertCommandeWritable($commande);
        $this->lines->delete($line);
    }

    private function line(Commande $commande, int $lineId): CommandeLine
    {
        $line = $this->emProvider->getEntityManager()->find(CommandeLine::class, $lineId);
        if ($line === null || $line->getCommande()?->getId() !== $commande->getId()) {
            throw new GestionConflictException('LINE_NOT_FOUND', 'Ligne d\'achat introuvable dans cette commande.', 404, ['lineId' => $lineId]);
        }

        return $line;
    }
}
