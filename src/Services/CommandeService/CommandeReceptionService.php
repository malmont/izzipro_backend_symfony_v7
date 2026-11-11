<?php

namespace App\Services\CommandeService;

use App\Entity\Commande;
use App\Entity\CommandeLine;
use App\Entity\InventoryMovements;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\OrderUseCase\UpdateStockAndInventoryUseCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Réception de stock des lignes d'achat (lot R1 de la gestion, 10/11/2026, `docs/lignes-achat-gestion.md` § 7) : SEULE écriture de stock de
 * l'application de gestion, jamais automatique. Chaque réception tient dans une transaction explicite, ligne, produit et variante verrouillés
 * (SELECT … FOR UPDATE, dans l'ordre de la vente : ligne, puis variante ; l'article seulement s'il n'a aucune variante) ; la variante reçue est celle de la ligne, sinon l'unique variante de l'article, sinon une variante par défaut créée
 * sous verrou (sans taille, couleur ni options, prix NULL, stock initial = stock actuel de l'article, pour que son recalcul ne l'efface pas) ;
 * un article à plusieurs variantes sans variante sur la ligne demande un choix (VARIANT_REQUIRED). Mouvement type 5 relié à la ligne
 * (`UpdateStockAndInventoryUseCase::executeReception`), annulation par mouvement inverse type 4. Permise sur une commande figée ou une période
 * fermée : c'est du stock, les chiffres figés ne changent pas. Prix, prix d'achat, coefficient, visibilité : intacts.
 */
class CommandeReceptionService
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly UpdateStockAndInventoryUseCase $stock,
        private readonly ContentAuditRecorder $audit,
    ) {
    }

    /**
     * Réception d'une ligne (route 1).
     *
     * @return array{line: CommandeLine, movement: InventoryMovements}
     * @throws GestionValidationException 422 (quantité, VARIANT_REQUIRED)
     * @throws GestionConflictException 409 NOTHING_TO_RECEIVE
     */
    public function receiveLine(CommandeLine $line, ?int $quantity, ?int $variantId, ?string $by): array
    {
        $em = $this->emProvider->getEntityManager();
        $result = $em->wrapInTransaction(function (EntityManagerInterface $em) use ($line, $quantity, $variantId): array {
            $this->lock($em, $line);
            $remaining = $line->getQuantityOrdered() - $line->getQuantityReceived();
            if ($remaining <= 0) {
                throw new GestionConflictException('NOTHING_TO_RECEIVE', 'Cette ligne est déjà entièrement reçue.', 409, ['lineId' => $line->getId()]);
            }
            $quantity ??= $remaining;
            if ($quantity < 1 || $quantity > $remaining) {
                throw new GestionValidationException(['quantity' => $quantity < 1 ? 'MUST_BE_POSITIVE' : 'EXCEEDS_REMAINING'], sprintf('quantité de 1 à %d attendue', $remaining));
            }
            $variant = $this->variantFor($em, $line, $variantId);
            if (is_array($variant)) {
                throw new GestionConflictException('VARIANT_REQUIRED', 'Choisissez la variante reçue.', 422, ['lineId' => $line->getId(), 'variants' => $variant]);
            }

            return ['line' => $line, 'movement' => $this->receive($em, $line, $variant, $quantity)];
        });
        $this->audit->record('commande-reception', $line->getCommande()->getId(), 'receive', null, ['lineId' => $line->getId(), 'quantity' => $result['movement']->getQuantity(), 'variantId' => $result['movement']->getProductVariant()?->getId()]);

        return $result;
    }

    /**
     * Réception en un geste (route 2) : tout ce qui peut l'être ; les lignes dont la variante est à choisir reviennent dans `needsVariant`,
     * sans être reçues. Idempotent : une commande déjà reçue rend `received` vide.
     *
     * @param list<array{lineId: int, quantity?: ?int, variantId?: ?int}>|null $only
     * @return array{received: list<array{lineId: int, quantity: int, movementId: ?int}>, needsVariant: list<array<string, mixed>>}
     */
    public function receiveCommande(Commande $commande, ?array $only, ?string $by): array
    {
        $em = $this->emProvider->getEntityManager();
        $wanted = null;
        if ($only !== null) {
            $wanted = [];
            foreach ($only as $i => $entry) {
                if (!is_array($entry) || !isset($entry['lineId']) || !is_numeric($entry['lineId'])) {
                    throw new GestionValidationException(["lines[$i].lineId" => 'INVALID_ID'], 'lineId attendu');
                }
                $wanted[(int) $entry['lineId']] = ['quantity' => isset($entry['quantity']) && is_numeric($entry['quantity']) ? (int) $entry['quantity'] : null, 'variantId' => isset($entry['variantId']) && is_numeric($entry['variantId']) ? (int) $entry['variantId'] : null];
            }
        }
        $result = $em->wrapInTransaction(function (EntityManagerInterface $em) use ($commande, $wanted): array {
            $em->getConnection()->fetchOne('SELECT id FROM commande WHERE id = ? FOR UPDATE', [$commande->getId()]);
            $lines = $em->getRepository(CommandeLine::class)->forCommande($commande);
            $byId = [];
            foreach ($lines as $line) {
                $byId[$line->getId()] = $line;
            }
            foreach (array_keys($wanted ?? []) as $lineId) {
                if (!isset($byId[$lineId])) {
                    throw new GestionConflictException('LINE_NOT_FOUND', 'Ligne d\'achat introuvable dans cette commande.', 404, ['lineId' => $lineId]);
                }
            }
            $received = [];
            $needs = [];
            foreach ($lines as $line) {
                if ($wanted !== null && !isset($wanted[$line->getId()])) {
                    continue;
                }
                $this->lock($em, $line);
                $remaining = $line->getQuantityOrdered() - $line->getQuantityReceived();
                if ($remaining <= 0) {
                    continue;
                }
                $quantity = $wanted[$line->getId()]['quantity'] ?? $remaining;
                if ($quantity < 1 || $quantity > $remaining) {
                    throw new GestionValidationException(['lines[' . $line->getId() . '].quantity' => $quantity < 1 ? 'MUST_BE_POSITIVE' : 'EXCEEDS_REMAINING'], sprintf('ligne %d : quantité de 1 à %d attendue', $line->getId(), $remaining));
                }
                $variant = $this->variantFor($em, $line, $wanted[$line->getId()]['variantId'] ?? null);
                if (is_array($variant)) {
                    $product = $line->getProduct();
                    $needs[] = ['lineId' => $line->getId(), 'product' => ['id' => $product->getId(), 'name' => $product->getName(), 'code' => $product->getCode()], 'variants' => $variant];
                    continue;
                }
                $movement = $this->receive($em, $line, $variant, $quantity);
                $received[] = ['lineId' => (int) $line->getId(), 'quantity' => $quantity, 'movement' => $movement];
            }

            return ['received' => $received, 'needsVariant' => $needs];
        });
        $out = array_map(fn ($r) => ['lineId' => $r['lineId'], 'quantity' => $r['quantity'], 'movementId' => $r['movement']->getId()], $result['received']);
        if ($out !== []) {
            $this->audit->record('commande-reception', $commande->getId(), 'receive', null, ['lines' => $out]);
        }

        return ['received' => $out, 'needsVariant' => $result['needsVariant']];
    }

    /**
     * Annulation de tout ou partie d'une réception (route 3) : mouvement inverse type 4 sur la variante qui a reçu ; jamais de stock négatif.
     *
     * @return array{line: CommandeLine, movement: InventoryMovements}
     */
    public function cancel(CommandeLine $line, ?int $quantity, ?string $by): array
    {
        $em = $this->emProvider->getEntityManager();
        $result = $em->wrapInTransaction(function (EntityManagerInterface $em) use ($line, $quantity): array {
            $this->lock($em, $line);
            if ($line->getQuantityReceived() <= 0) {
                throw new GestionConflictException('NOTHING_TO_CANCEL', 'Rien n\'a été reçu sur cette ligne.', 409, ['lineId' => $line->getId()]);
            }
            $quantity ??= $line->getQuantityReceived();
            if ($quantity < 1 || $quantity > $line->getQuantityReceived()) {
                throw new GestionValidationException(['quantity' => $quantity < 1 ? 'MUST_BE_POSITIVE' : 'EXCEEDS_RECEIVED'], sprintf('quantité de 1 à %d attendue', $line->getQuantityReceived()));
            }
            // variante qui a reçu : solde net des mouvements de la ligne, la plus récemment approvisionnée d'abord
            $variantId = $em->getConnection()->fetchOne('SELECT product_variant_id FROM inventory_movements WHERE commande_line_id = ? GROUP BY product_variant_id HAVING SUM(quantity) > 0 ORDER BY MAX(id) DESC LIMIT 1', [$line->getId()]);
            if ($variantId === false) {
                throw new GestionConflictException('NOTHING_TO_CANCEL', 'Aucun mouvement de réception à annuler sur cette ligne.', 409, ['lineId' => $line->getId()]);
            }
            $em->getConnection()->fetchOne('SELECT id FROM product_variant WHERE id = ? FOR UPDATE', [$variantId]);
            $variant = $em->find(ProductVariant::class, (int) $variantId);
            $em->refresh($variant);
            if ($variant->getStockQuantity() < $quantity) {
                throw new GestionConflictException('STOCK_INSUFFICIENT', 'Le stock actuel ne permet pas d\'annuler cette quantité (articles déjà vendus).', 409, ['variantId' => $variant->getId(), 'stock' => $variant->getStockQuantity()]);
            }
            $movement = $this->stock->executeReception($variant, -$quantity, $line);
            $line->setQuantityReceived($line->getQuantityReceived() - $quantity)->setUpdatedAt(new \DateTimeImmutable());
            $em->flush();

            return ['line' => $line, 'movement' => $movement];
        });
        $this->audit->record('commande-reception', $line->getCommande()->getId(), 'cancel', null, ['lineId' => $line->getId(), 'quantity' => $quantity ?? null, 'movementId' => $result['movement']->getId()]);

        return $result;
    }

    /** Forme d'un mouvement dans les réponses */
    public static function movementArray(InventoryMovements $m): array
    {
        return ['id' => $m->getId(), 'variantId' => $m->getProductVariant()?->getId(), 'quantity' => $m->getQuantity(), 'type' => $m->getMovementType()?->getId(),
            'stockBefore' => $m->getStockBeforeMovement(), 'stockAfter' => $m->getStockAfterMovement() === null ? null : (int) $m->getStockAfterMovement()];
    }

    // ----- interne -----

    /**
     * Verrou de la ligne seulement. Ordre des verrous aligné sur la vente (variante, puis article par le rappel de ProductVariant) pour
     * éviter tout interblocage (relecture de la session de la caisse) : l'article n'est verrouillé que s'il n'a aucune variante (variantFor).
     */
    private function lock(EntityManagerInterface $em, CommandeLine $line): void
    {
        $em->getConnection()->fetchOne('SELECT id FROM commande_line WHERE id = ? FOR UPDATE', [$line->getId()]);
        $em->refresh($line);
    }

    /**
     * Variante à recevoir, ou liste des variantes à proposer quand le choix revient au gérant.
     *
     * @return ProductVariant|list<array{id: int, size: ?string, color: ?string}>
     */
    private function variantFor(EntityManagerInterface $em, CommandeLine $line, ?int $variantId): ProductVariant|array
    {
        $product = $line->getProduct();
        if ($line->getVariant() !== null) {
            return $line->getVariant();
        }
        $variants = $em->getRepository(ProductVariant::class)->findBy(['product' => $product], ['id' => 'ASC']);
        if ($variants === [] && $variantId === null) {
            // aucune variante : aucune vente possible en face ; verrou de l'article, puis relecture avant de créer la variante par défaut
            $em->getConnection()->fetchOne('SELECT id FROM product WHERE id = ? FOR UPDATE', [$product->getId()]);
            $variants = $em->getRepository(ProductVariant::class)->createQueryBuilder('v')->where('v.product = :p')->setParameter('p', $product)->orderBy('v.id', 'ASC')->getQuery()->setHint(\Doctrine\ORM\Query::HINT_REFRESH, true)->getResult();
            $em->refresh($product);
        }
        if ($variantId !== null) {
            foreach ($variants as $v) {
                if ($v->getId() === $variantId) {
                    return $v;
                }
            }
            throw new GestionValidationException(['variantId' => 'VARIANT_NOT_IN_PRODUCT'], 'Variante absente de l\'article de la ligne');
        }
        if (count($variants) === 1) {
            return $variants[0];
        }
        if (count($variants) > 1) {
            return array_map(fn (ProductVariant $v) => ['id' => (int) $v->getId(), 'size' => $v->getSize()?->getName(), 'color' => $v->getColor()?->getName()], $variants);
        }
        // aucune variante : variante par défaut, sans attribut ni option, prix NULL (celui de la fiche s'applique), stock initial = stock actuel
        $variant = (new ProductVariant())->setStockQuantity((int) ($product->getQuantity() ?? 0))->setPrice(null);
        $variant->setProduct($product);
        $product->addVariant($variant);
        $em->persist($variant);
        $em->flush();

        return $variant;
    }

    private function receive(EntityManagerInterface $em, CommandeLine $line, ProductVariant $variant, int $quantity): InventoryMovements
    {
        $em->getConnection()->fetchOne('SELECT id FROM product_variant WHERE id = ? FOR UPDATE', [$variant->getId()]);
        $em->refresh($variant);
        $movement = $this->stock->executeReception($variant, $quantity, $line);
        $line->setQuantityReceived($line->getQuantityReceived() + $quantity)->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        return $movement;
    }
}
