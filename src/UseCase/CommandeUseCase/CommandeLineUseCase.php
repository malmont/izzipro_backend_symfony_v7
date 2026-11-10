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
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly CommandeLineService $lines, private readonly PeriodGuard $guard, private readonly ?\App\Services\CommandeService\CommandeReceptionService $reception = null)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(Commande $commande): array
    {
        return array_map(fn (CommandeLine $l) => CommandeProductAttachmentService::lineArray($l), $this->emProvider->getEntityManager()->getRepository(CommandeLine::class)->forCommande($commande));
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} lignes à recevoir, avec leur commande (lot R1) */
    public function toReceive(int $page, int $limit): array
    {
        [$lines, $total] = $this->emProvider->getEntityManager()->getRepository(CommandeLine::class)->toReceive($page, $limit);

        return [array_map(fn (CommandeLine $l) => CommandeProductAttachmentService::lineArray($l) + ['commande' => ['id' => $l->getCommande()->getId(), 'name' => $l->getCommande()->getName(), 'date' => $l->getCommande()->getDate()?->format('Y-m-d H:i:s')]], $lines), $total];
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

    /** Réception d'une ligne (lot R1) : POST /api/commandes/{id}/lignes/{lineId}/reception { quantity?, variantId? } */
    public function receive(Commande $commande, int $lineId, array $data, ?string $by): array
    {
        $line = $this->line($commande, $lineId);
        [$quantity, $variantId] = $this->receptionInput($data);
        $result = $this->reception->receiveLine($line, $quantity, $variantId, $by);

        return ['line' => CommandeProductAttachmentService::lineArray($result['line']), 'movement' => \App\Services\CommandeService\CommandeReceptionService::movementArray($result['movement'])];
    }

    /** Réception en un geste (lot R1) : POST /api/commandes/{id}/reception { lines?: [{ lineId, quantity?, variantId? }] } */
    public function receiveAll(Commande $commande, array $data, ?string $by): array
    {
        if (array_key_exists('lines', $data) && $data['lines'] !== null && !is_array($data['lines'])) {
            throw new \App\Services\GestionService\GestionValidationException(['lines' => 'MUST_BE_LIST'], 'lines : liste attendue');
        }
        $result = $this->reception->receiveCommande($commande, $data['lines'] ?? null, $by);
        $this->emProvider->getEntityManager()->refresh($commande);
        $lines = $this->list($commande);

        return $result + ['lines' => $lines, 'commande' => ['id' => $commande->getId(), 'remaining' => array_sum(array_column($lines, 'remaining')), 'receptionStatus' => $commande->receptionStatus()]];
    }

    /** Annulation d'une réception (lot R1) : POST /api/commandes/{id}/lignes/{lineId}/reception/annuler { quantity? } */
    public function cancelReception(Commande $commande, int $lineId, array $data, ?string $by): array
    {
        $line = $this->line($commande, $lineId);
        [$quantity] = $this->receptionInput($data);
        $result = $this->reception->cancel($line, $quantity, $by);

        return ['line' => CommandeProductAttachmentService::lineArray($result['line']), 'movement' => \App\Services\CommandeService\CommandeReceptionService::movementArray($result['movement'])];
    }

    /** @return array{0: ?int, 1: ?int} quantité et variante facultatives */
    private function receptionInput(array $data): array
    {
        $input = new GestionInput($data);
        $quantity = array_key_exists('quantity', $data) && $data['quantity'] !== null ? $input->integer('quantity') : null;
        $variantId = array_key_exists('variantId', $data) && $data['variantId'] !== null ? $input->id('variantId') : null;
        $input->check();

        return [$quantity, $variantId];
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
