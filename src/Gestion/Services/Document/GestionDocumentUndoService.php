<?php

namespace App\Gestion\Services\Document;

use App\Entity\Commande;
use App\Entity\CommandeLine;
use App\Entity\Fournisseur;
use App\Entity\Product;
use App\Gestion\Entity\GestionDocument;
use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\PeriodCalendar;
use App\Services\CommandeService\CommandeLineService;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantEntityManagerProvider;

/**
 * Annulation d'un bloc de la confirmation d'une facture fournisseur (lot IA-FACT-1, 13/11/2026, `docs/conversation-gestion.md` § 6).
 * Règle du propriétaire : aucune écriture préparée sans annulation possible. La confirmation note sur le document tout ce qu'elle a créé
 * (`confirmed.undo` : commande, lignes d'achat, articles et variantes créés, fournisseur créé, frais de port) ; l'annulation retire
 * exactement cela, tout ou rien, dans les 24 heures, une seule fois, et remet le document à « lu » avec son brouillon.
 *
 * Elle est refusée, avec la liste des raisons, dès que quelque chose a bougé depuis : période fermée, commande figée ou modifiée, ligne
 * reçue en stock, article créé vendu ou modifié, frais de port modifié. Un fournisseur créé par la confirmation n'est retiré que s'il ne
 * sert à rien d'autre ; sinon il est gardé, sans bloquer.
 */
class GestionDocumentUndoService
{
    public const WINDOW_HOURS = 24;
    public const AUDIT_RESOURCE = 'gestion-documents';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PeriodCalendar $calendar,
        private readonly CommandeLineService $lines,
        private readonly ContentAuditRecorder $audit,
    ) {
    }

    /**
     * Fenêtre d'annulation d'un document, sans rien lire en base : `available` dit seulement qu'une annulation peut être tentée
     * (confirmé, créations notées, délai non écoulé) ; ce qui a bougé depuis est constaté par `blockers()` ou à l'annulation.
     *
     * @return array{available: bool, until: ?string}
     */
    public static function window(GestionDocument $document): array
    {
        $until = $document->getConfirmedAt()?->modify('+' . self::WINDOW_HOURS . ' hours');
        $recorded = is_array($document->getConfirmed()['undo'] ?? null);

        return ['available' => $document->isConfirmed() && $recorded && $until !== null && $until > new \DateTimeImmutable(), 'until' => $document->isConfirmed() && $recorded ? $until?->format(\DateTimeInterface::ATOM) : null];
    }

    /**
     * Ce qui empêche l'annulation, constaté sur les données d'aujourd'hui (lecture seule).
     *
     * @return list<array{code: string, message: string}>
     */
    public function blockers(GestionDocument $document): array
    {
        $undo = $document->getConfirmed()['undo'] ?? null;
        if (!is_array($undo)) {
            return [];
        }
        $em = $this->emProvider->getEntityManager();
        $conn = $em->getConnection();
        $reasons = [];
        $add = function (string $code, string $message) use (&$reasons): void {
            $reasons[$code] ??= ['code' => $code, 'message' => $message];
        };
        $commande = $em->find(Commande::class, (int) $undo['commandeId']);
        if ($commande === null) {
            $add('COMMANDE_CHANGED', 'La commande créée par cette confirmation n\'existe plus.');

            return array_values($reasons);
        }
        if ($commande->getIsClosed()) {
            $add('COMMANDE_CLOSED', 'La commande est figée.');
        }
        $period = $commande->getCollections();
        if ($period !== null && $this->calendar->isClosed($period)) {
            $add('PERIOD_CLOSED', 'La période de la commande est fermée.');
        }
        // Une facture ajoutée à la même commande après celle-ci : on annule dans l'ordre inverse des ajouts (lot IA-FACT-2-A). Le blocage
        // nomme la facture à annuler d'abord, avec son document, pour que l'application ouvre son fil.
        $at = $document->getConfirmedAt()?->format('Y-m-d H:i:s');
        $later = $conn->fetchAssociative('SELECT id, confirmed, confirmed_at FROM gestion_document WHERE commande_id = :c AND id <> :id AND status = :s AND (confirmed_at > :at OR (confirmed_at = :at AND id > :id)) ORDER BY confirmed_at DESC, id DESC LIMIT 1',
            ['c' => $commande->getId(), 'id' => $document->getId(), 's' => GestionDocument::STATUS_CONFIRMED, 'at' => $at]); // même seconde : départagée par l'identifiant
        if ($later !== false) {
            $number = (string) (json_decode((string) $later['confirmed'], true)['invoice']['number'] ?? '');
            $at = (new \DateTimeImmutable((string) $later['confirmed_at']))->format(\DateTimeInterface::ATOM);
            $reasons['LATER_DOCUMENT'] = ['code' => 'LATER_DOCUMENT', 'message' => sprintf('Annulez d\'abord la facture %sajoutée à cette commande le %s.', $number !== '' ? 'n° ' . $number . ' ' : '', substr($at, 0, 10)),
                'documentId' => (int) $later['id'], 'invoiceNumber' => $number !== '' ? $number : null, 'confirmedAt' => $at];
        }
        // Lignes d'achat notées par la confirmation : au même état qu'après elle, aucune reçue depuis. Nouvelle commande : toutes ses
        // lignes, ni plus ni moins. Ajout à une commande : seulement les lignes que la facture a créées ou grossies.
        $addMode = ($undo['mode'] ?? 'new') === 'add';
        $expected = [];
        $receivedBefore = [];
        foreach ($undo['lines'] ?? [] as $line) {
            $expected[(int) $line['id']] = [(int) $line['quantity'], $line['unitCost'] === null ? null : (int) $line['unitCost']];
            $receivedBefore[(int) $line['id']] = (int) ($line['received'] ?? 0);
        }
        $current = [];
        foreach ($em->getRepository(CommandeLine::class)->forCommande($commande) as $line) {
            $id = (int) $line->getId();
            if ($addMode && !isset($expected[$id])) {
                continue; // ligne que cette facture n'a pas touchée
            }
            $current[$id] = [$line->getQuantityOrdered(), $line->getUnitCostCents()];
            if ($line->getQuantityReceived() > ($receivedBefore[$id] ?? 0)) {
                $add('LINE_RECEIVED', 'Une ligne de cette commande a été reçue en stock.');
            }
        }
        ksort($expected);
        ksort($current);
        if ($expected !== $current && $later === false) {
            $add('COMMANDE_CHANGED', 'Les lignes de la commande ont été modifiées depuis la confirmation.');
        }
        // Frais de port : celui de la confirmation, au même montant (ou aucun). Lu en base : dans la requête de la confirmation, la
        // commande en mémoire ne porte pas encore son frais de port (côté inverse de l'association)
        $shipping = $conn->fetchAssociative('SELECT id, price FROM frais_de_port WHERE commande_id = ? ORDER BY id LIMIT 1', [$commande->getId()]);
        $recorded = $undo['fraisDePort'] ?? null;
        $same = $shipping === false ? $recorded === null : ($recorded !== null && (int) $shipping['id'] === (int) $recorded['id'] && (int) $shipping['price'] === (int) $recorded['price']);
        if ($addMode && $recorded === null) {
            $same = true; // la facture ajoutée n'a pas touché au frais de port de la commande
        }
        if (!$same && $later === false) {
            $add('SHIPPING_CHANGED', 'Le frais de port de la commande a été modifié depuis la confirmation.');
        }
        // Articles créés : jamais vendus, sans mouvement de stock, sans autre achat, fiche inchangée
        foreach ($undo['products'] ?? [] as $recordedProduct) {
            $product = $em->find(Product::class, (int) $recordedProduct['id']);
            if ($product === null) {
                $add('PRODUCT_CHANGED', 'Un article créé par cette confirmation n\'existe plus.');
                continue;
            }
            $id = (int) $product->getId();
            if ((int) $conn->fetchOne('SELECT COUNT(*) FROM order_items oi JOIN product_variant pv ON pv.id = oi.product_variant_id WHERE pv.product_id = ?', [$id]) > 0) {
                $add('PRODUCT_SOLD', 'Un article créé par cette confirmation a été vendu.');
            }
            $variantIds = array_values(array_map(fn ($v) => (int) $v->getId(), $product->getVariants()->toArray()));
            sort($variantIds);
            $recordedVariants = array_map('intval', $recordedProduct['variantIds'] ?? []);
            sort($recordedVariants);
            $moved = (int) $conn->fetchOne('SELECT COUNT(*) FROM inventory_movements im JOIN product_variant pv ON pv.id = im.product_variant_id WHERE pv.product_id = ?', [$id]) > 0;
            $otherPurchases = (int) $conn->fetchOne('SELECT COUNT(*) FROM commande_line WHERE product_id = ? AND commande_id <> ?', [$id, $commande->getId()]) > 0;
            $edited = $product->getName() !== $recordedProduct['name'] || abs((float) $product->getPrice() - (float) $recordedProduct['price']) > 0.001
                || abs((float) $product->getPurchasePrice() - (float) $recordedProduct['purchasePrice']) > 0.001 || $product->isWeb() || $product->isPos() || (int) $product->getQuantity() !== 0;
            if ($moved || $otherPurchases || $edited || $variantIds !== $recordedVariants) {
                $add('PRODUCT_CHANGED', sprintf('L\'article « %s », créé par cette confirmation, a été modifié, mis en vente, reçu ou racheté depuis.', (string) $product->getName()));
            }
        }

        return array_values($reasons);
    }

    /**
     * Annule la confirmation : 409 `DOCUMENT_NOT_CONFIRMED`, `ALREADY_UNDONE`, `UNDO_UNAVAILABLE` (confirmée avant ce lot ou autre type
     * que facture), `UNDO_EXPIRED`, `UNDO_BLOCKED { reasons }`.
     *
     * @return array{commandeId: int, removed: array{lines: int, products: int, shipping: bool, supplier: bool}, kept: list<string>}
     */
    public function undo(GestionDocument $document): array
    {
        $em = $this->emProvider->getEntityManager();
        $conn = $em->getConnection();
        if (!$document->isConfirmed()) {
            $undone = (int) $conn->fetchOne('SELECT COUNT(*) FROM content_audit_log WHERE resource = ? AND resource_id = ? AND action = ?', [self::AUDIT_RESOURCE, (string) $document->getId(), 'undo']) > 0;
            throw $undone
                ? new GestionConflictException('ALREADY_UNDONE', 'Cette confirmation a déjà été annulée.')
                : new GestionConflictException('DOCUMENT_NOT_CONFIRMED', 'Ce document n\'est pas confirmé : rien à annuler.');
        }
        $confirmed = $document->getConfirmed() ?? [];
        $undo = $confirmed['undo'] ?? null;
        if ($document->getType() !== GestionDocument::TYPE_FACTURE || !is_array($undo)) {
            throw new GestionConflictException('UNDO_UNAVAILABLE', 'Cette confirmation ne peut pas être annulée d\'un bloc (confirmée avant le 13/11/2026, ou autre qu\'une facture fournisseur).');
        }
        $window = self::window($document);
        if (!$window['available']) {
            throw new GestionConflictException('UNDO_EXPIRED', sprintf('L\'annulation n\'est possible que dans les %d heures qui suivent la confirmation.', self::WINDOW_HOURS), 409, ['until' => $window['until']]);
        }
        $reasons = $this->blockers($document);
        if ($reasons !== []) {
            throw new GestionConflictException('UNDO_BLOCKED', 'Annulation impossible : ' . implode(' ', array_column($reasons, 'message')), 409, ['reasons' => $reasons]);
        }

        $commandeId = (int) $undo['commandeId'];
        if (($undo['mode'] ?? 'new') === 'add') {
            return $this->undoAddition($document, $confirmed, $undo);
        }
        $removed = ['lines' => count($undo['lines'] ?? []), 'products' => count($undo['products'] ?? []), 'shipping' => ($undo['fraisDePort'] ?? null) !== null, 'supplier' => false];
        $kept = [];
        $conn->beginTransaction();
        try {
            // Le document redevient « lu » avant que sa commande disparaisse : il n'est pas un justificatif orphelin, il garde ses fichiers et son brouillon
            $document->setStatus(GestionDocument::STATUS_EXTRACTED)->setConfirmedAt(null)->setConfirmed(null)->setCommandeId(null)
                ->setExpiresAt((new \DateTimeImmutable())->modify('+' . GestionDocumentService::RETENTION_DAYS . ' days'));
            $commande = $em->find(Commande::class, $commandeId);
            $commande->setDocument(null);
            $em->flush();
            // Articles de la commande : ceux créés par la confirmation seront retirés ; ceux déjà au catalogue gardent tout, sauf leurs
            // raccourcis d'achat, recalculés après le retrait
            $createdIds = array_map(fn (array $p) => (int) $p['id'], $undo['products'] ?? []);
            $products = [];
            // Lignes d'achat et frais de port retirés par l'EntityManager, avant la commande : la base les supprimerait en cascade, mais
            // ceux déjà chargés resteraient en mémoire, reliés à une commande disparue, et feraient échouer l'écriture suivante
            foreach ($em->getRepository(CommandeLine::class)->forCommande($commande) as $line) {
                if ($line->getProduct() !== null) {
                    $products[(int) $line->getProduct()->getId()] = $line->getProduct();
                }
                $em->remove($line);
            }
            foreach ($em->getRepository(\App\Entity\FraisDePort::class)->findBy(['commande' => $commande]) as $shipping) {
                $em->remove($shipping);
            }
            $commande->setFraisDePort(null);
            $em->remove($commande);
            $em->flush();
            foreach ($products as $product) {
                $em->refresh($product); // l'article ne doit plus porter la commande retirée (raccourci `commande_id`, mis à NULL par la base)
            }
            foreach ($createdIds as $id) {
                $product = $em->find(Product::class, $id);
                if ($product !== null) {
                    $em->remove($product); // variantes et photos suivent
                }
            }
            $em->flush();
            foreach ($products as $id => $product) {
                if (!in_array($id, $createdIds, true)) {
                    $this->lines->refreshShortcuts($product);
                }
            }
            if (($undo['supplierId'] ?? null) !== null) {
                $supplier = $em->find(Fournisseur::class, (int) $undo['supplierId']);
                $used = $supplier !== null && (int) $conn->fetchOne('SELECT COUNT(*) FROM commande WHERE fournisseur_id = ?', [$supplier->getId()]) > 0;
                if ($supplier !== null && !$used) {
                    $em->remove($supplier);
                    $em->flush();
                    $removed['supplier'] = true;
                } elseif ($supplier !== null) {
                    $kept[] = 'supplier';
                }
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
        $result = ['commandeId' => $commandeId, 'removed' => $removed, 'kept' => $kept];
        $this->audit->record(self::AUDIT_RESOURCE, $document->getId(), 'undo', array_diff_key($confirmed, ['undo' => true]) + ['created' => $undo], $result);

        return $result;
    }

    /**
     * Annulation d'une facture AJOUTÉE à une commande ouverte (lot IA-FACT-2-A) : la commande reste ; les lignes que la facture a créées
     * sont retirées, celles qu'elle a grossies reviennent à leur état d'avant (quantité, coût), le budget et le frais de port aussi,
     * les articles créés sont retirés.
     *
     * @param array<string, mixed> $confirmed
     * @param array<string, mixed> $undo
     *
     * @return array<string, mixed>
     */
    private function undoAddition(GestionDocument $document, array $confirmed, array $undo): array
    {
        $em = $this->emProvider->getEntityManager();
        $conn = $em->getConnection();
        $commandeId = (int) $undo['commandeId'];
        $removed = ['lines' => 0, 'products' => count($undo['products'] ?? []), 'shipping' => false, 'supplier' => false];
        $restored = ['lines' => 0, 'shipping' => false, 'budget' => (int) ($undo['budget']['added'] ?? 0)];
        $conn->beginTransaction();
        try {
            $commande = $em->find(Commande::class, $commandeId);
            $document->setStatus(GestionDocument::STATUS_EXTRACTED)->setConfirmedAt(null)->setConfirmed(null)->setCommandeId(null)
                ->setExpiresAt((new \DateTimeImmutable())->modify('+' . GestionDocumentService::RETENTION_DAYS . ' days'));
            if (($undo['documentSet'] ?? false) && $commande->getDocument()?->getId() === $document->getId()) {
                $commande->setDocument(null);
            }
            $createdIds = array_map(fn (array $p) => (int) $p['id'], $undo['products'] ?? []);
            $products = [];
            foreach ($undo['lines'] ?? [] as $recorded) {
                $line = $em->find(CommandeLine::class, (int) $recorded['id']);
                if ($line === null) {
                    continue;
                }
                $products[(int) $line->getProduct()->getId()] = $line->getProduct();
                if (($recorded['created'] ?? true) || ($recorded['before'] ?? null) === null) {
                    $em->remove($line);
                    ++$removed['lines'];
                } else {
                    $line->setQuantityOrdered((int) $recorded['before']['quantity'])->setUnitCostCents($recorded['before']['unitCost'] === null ? null : (int) $recorded['before']['unitCost'])
                        ->setEstimated((bool) ($recorded['before']['estimated'] ?? false))->setUpdatedAt(new \DateTimeImmutable());
                    ++$restored['lines'];
                }
            }
            $commande->setBudget(max(0, ($commande->getBudget() ?? 0) - $restored['budget']));
            $shipping = $undo['fraisDePort'] ?? null;
            if ($shipping !== null) {
                $entity = $em->find(\App\Entity\FraisDePort::class, (int) $shipping['id']);
                if ($entity !== null && ($shipping['added'] ?? false)) {
                    $entity->setPrice($shipping['priceBefore'] === null ? null : (int) $shipping['priceBefore']);
                    $restored['shipping'] = true;
                } elseif ($entity !== null) {
                    $commande->setFraisDePort(null);
                    $em->remove($entity);
                    $removed['shipping'] = true;
                }
            }
            $em->flush();
            foreach ($createdIds as $id) {
                $product = $em->find(Product::class, $id);
                if ($product !== null) {
                    $em->remove($product);
                }
            }
            $em->flush();
            foreach ($products as $id => $product) {
                if (!in_array($id, $createdIds, true)) {
                    $em->refresh($product);
                    $this->lines->refreshShortcuts($product);
                }
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
        $result = ['commandeId' => $commandeId, 'mode' => 'add', 'removed' => $removed, 'restored' => $restored, 'kept' => []];
        $this->audit->record(self::AUDIT_RESOURCE, $document->getId(), 'undo', array_diff_key($confirmed, ['undo' => true]) + ['created' => $undo], $result);

        return $result;
    }
}
