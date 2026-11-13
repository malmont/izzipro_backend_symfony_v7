<?php

namespace App\Gestion\UseCase;

use App\Entity\Fournisseur;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Gestion\Entity\GestionDocument;
use App\Gestion\Services\Document\GestionDocumentReader;
use App\Gestion\Services\Document\GestionDocumentService;
use App\Gestion\Services\Document\GestionInvoiceDraft;
use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionInput;
use App\Gestion\Services\GestionValidationException;
use App\Services\BoutiqueSettingsService\SiteCurrencyService;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\TenantEntityManagerProvider;

/**
 * POST /api/gestion/documents/{id}/apercu (lot APERÇU-FACT, 12/11/2026, `docs/lecture-documents-gestion.md` § 4 bis) : ce que donnerait la
 * confirmation d'une facture fournisseur telle que le gérant l'a corrigée, SANS RIEN ÉCRIRE (ni brouillon, ni journal). Par ligne : le
 * coût converti dans la devise du site, le prix de vente prévu d'un article créé (la règle de la confirmation :
 * `GestionInvoiceDraft::groupPrice`) et sa marge ; les totaux recalculés et l'écart avec le total lu ; les refus que la confirmation
 * opposerait à ces champs (`issues`, mêmes chemins et mêmes codes), sans bloquer l'aperçu.
 *
 * Lot IA-ACT-5 (13/11/2026) : le prix d'un article créé suit le coefficient par défaut et l'arrondi du site ; sa mise en ligne par canal
 * (`publishPos`, `publishWeb` : `no`, `now`, `reception`) et ses mentions (`STOCK_ZERO`, `NO_PHOTO`) ; pour un article déjà au catalogue,
 * le nouveau prix décidé sur la facture (`price: { mode, coefficient, newPrice }`), sinon son prix est gardé.
 */
class PreviewGestionDocumentUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly GestionDocumentService $documents,
        private readonly TenantCurrencyProvider $currency,
        private readonly ?\App\Gestion\Services\Document\InvoiceTargetPolicy $targets = null, // lot IA-FACT-2-A : ajout à une commande ouverte
        private readonly ?\App\Gestion\Services\GestionPriceSettingsService $settings = null, // lot IA-ACT-5 : coefficient par défaut et arrondi du site
    ) {
    }

    /**
     * @param array<string, mixed> $body corps de la confirmation (fournisseur et date non exigés ici)
     *
     * @return array<string, mixed>
     */
    public function execute(GestionDocument $document, array $body): array
    {
        $this->documents->assertOpen($document); // 409 DOCUMENT_CONFIRMED, 410 DOCUMENT_EXPIRED
        if ($document->getType() !== GestionDocument::TYPE_FACTURE) {
            throw new GestionConflictException('DOCUMENT_TYPE', 'L\'aperçu ne concerne que les factures fournisseurs.', 422);
        }
        if (!in_array($document->getStatus(), [GestionDocument::STATUS_EXTRACTED, GestionDocument::STATUS_FAILED], true)) {
            throw new GestionConflictException('DOCUMENT_IN_PROGRESS', 'Lecture en cours : attendez son résultat.');
        }
        [$lines, $issues] = GestionInvoiceDraft::parseLines($body);
        if (isset($issues['lines'])) {
            throw new GestionValidationException(['lines' => 'REQUIRED'], 'Aperçu impossible : aucune ligne');
        }
        $em = $this->emProvider->getEntityManager();
        $extraction = $document->getExtraction() ?? [];
        $site = strtoupper($this->currency->code());

        // Devise de la facture : celle du fournisseur du corps (règle de la confirmation) ; sans fournisseur, celle lue sur le document
        $invoiceIn = new GestionInput(is_array($body['invoice'] ?? null) ? $body['invoice'] : []);
        $exchangeRate = $invoiceIn->decimal('exchangeRate', false);
        $issues += GestionInvoiceDraft::prefix('invoice', $invoiceIn->errors());
        $supplierIn = is_array($body['supplier'] ?? null) ? $body['supplier'] : [];
        $purchaseCurrency = is_string($extraction['invoice']['currency'] ?? null) ? $extraction['invoice']['currency'] : null;
        if (isset($supplierIn['id']) && is_numeric($supplierIn['id'])) {
            $supplier = $em->find(Fournisseur::class, (int) $supplierIn['id']);
            if ($supplier === null) {
                $issues['supplier.id'] = 'FOURNISSEUR_NOT_FOUND';
            } else {
                $purchaseCurrency = $supplier->getCurrency();
            }
        } elseif (is_array($supplierIn['create'] ?? null)) {
            $asked = (new GestionInput($supplierIn['create']))->text('currency', false, 3);
            if ($asked !== null && !in_array(strtoupper($asked), SiteCurrencyService::SUPPORTED, true)) {
                $issues['supplier.create.currency'] = 'INVALID_CURRENCY';
            } else {
                $purchaseCurrency = $asked;
            }
        }
        $invoiceCurrency = $purchaseCurrency !== null ? strtoupper($purchaseCurrency) : $site;
        // Cible (lot IA-FACT-2-A) : la facture s'ajoute à une commande ouverte du même fournisseur ; sa devise et son taux s'appliquent
        $target = null;
        $targetIn = is_array($body['target'] ?? null) ? $body['target'] : null;
        if ($targetIn !== null && isset($targetIn['commandeId'])) {
            $target = is_numeric($targetIn['commandeId']) ? $em->find(\App\Entity\Commande::class, (int) $targetIn['commandeId']) : null;
            $reason = $target === null ? 'COMMANDE_NOT_FOUND' : ($this->targets?->reason($target, $supplier ?? null) ?? ($this->targets === null ? 'SUPPLIER_MISMATCH' : null));
            if ($reason !== null) {
                $issues['target'] = $reason === 'COMMANDE_NOT_FOUND' ? $reason : 'TARGET_NOT_ALLOWED';
                $targetReason = $reason;
                $target = null;
            } else {
                $invoiceCurrency = strtoupper($target->getCurrency() ?? $site);
                $exchangeRate = $target->getExchangeRate();
            }
        }
        $rate = GestionInvoiceDraft::effectiveRate($invoiceCurrency, $site, $exchangeRate);
        if ($rate === null) {
            $issues['exchangeRate'] = 'EXCHANGE_RATE_REQUIRED';
        }

        // Articles existants : prix de vente actuel (gardé, sauf décision portée par la ligne : lot IA-ACT-5)
        $currentPrices = [];
        $byProduct = [];
        $names = [];
        foreach ($lines as $line) {
            if ($line['productId'] === null || $line['create'] !== null) {
                continue;
            }
            $product = $em->find(Product::class, $line['productId']);
            if ($product === null) {
                $issues["lines[{$line['n']}].productId"] = 'PRODUCT_NOT_FOUND';
                continue;
            }
            $variant = $line['variantId'] !== null ? $em->find(ProductVariant::class, $line['variantId']) : null;
            if ($line['variantId'] !== null && ($variant === null || $variant->getProduct()?->getId() !== $product->getId())) {
                $issues["lines[{$line['n']}].variantId"] = 'VARIANT_NOT_IN_PRODUCT';
                continue;
            }
            $price = $variant?->getPrice() ?? $product->getPrice();
            $currentPrices[$line['n']] = $price !== null ? (int) round((float) $price) : null;
            $byProduct[(int) $product->getId()][] = $line;
            $names[(int) $product->getId()] = ['price' => $product->getPrice() !== null ? (int) round((float) $product->getPrice()) : null];
            if ($target !== null && $line['quantity'] !== null) {
                // la ligne de l'article dans la commande cible, avant et après l'ajout (un article n'a qu'une ligne par commande)
                $key = $product->getId() . '/' . ($variant?->getId() ?? '-');
                $existing = $em->getRepository(\App\Entity\CommandeLine::class)->findOne($target, $product, $variant);
                $merge[$key] ??= ['before' => $existing === null ? null : ['quantity' => $existing->getQuantityOrdered(), 'unitCost' => $existing->getUnitCostCents()], 'quantity' => 0, 'cost' => 0, 'priced' => 0];
                $merge[$key]['quantity'] += $line['quantity'];
                if ($line['unitCost'] !== null) {
                    $merge[$key]['cost'] += $line['quantity'] * $line['unitCost'];
                    $merge[$key]['priced'] += $line['quantity'];
                }
                $mergeKeys[$line['n']] = $key;
            }
        }
        $lineAfter = [];
        foreach ($merge ?? [] as $key => $m) {
            $added = $m['priced'] > 0 ? (int) round($m['cost'] / $m['priced']) : null;
            $before = $m['before'];
            $quantity = ($before['quantity'] ?? 0) + $m['quantity'];
            $unitCost = match (true) {
                $added === null => $before['unitCost'] ?? null,
                $before === null || $before['unitCost'] === null || $quantity <= 0 => $added,
                default => (int) round(($before['quantity'] * $before['unitCost'] + $m['quantity'] * $added) / $quantity),
            };
            $lineAfter[$key] = ['before' => $before, 'after' => ['quantity' => $quantity, 'unitCost' => $unitCost]];
        }

        // Articles à créer : un prix par groupe, répété sur chaque ligne du groupe ; aucun si une ligne du groupe est refusée
        $defaultCoefficient = $this->settings?->defaultCoefficient() ?? GestionInvoiceDraft::DEFAULT_COEFFICIENT;
        $rounding = $this->settings?->rounding() ?? \App\Gestion\Pricing\PriceRounding::NONE;
        $planned = [];
        $publishing = [];
        foreach (GestionInvoiceDraft::groups($lines) as $group) {
            $valid = $rate !== null;
            foreach ($group as $line) {
                foreach (array_keys($issues) as $path) {
                    $valid = $valid && $path !== "lines[{$line['n']}]" && !str_starts_with($path, "lines[{$line['n']}].");
                }
            }
            $pricing = $valid ? GestionInvoiceDraft::groupPrice($group, $rate, $defaultCoefficient, $rounding) : null;
            $publish = GestionInvoiceDraft::groupPublish($group);
            // Mentions de la mise en ligne : stock 0 jusqu'à la réception (article visible tout de suite), sans photo sur la boutique
            $photo = array_filter($group, fn ($l) => isset($document->getFiles()['linePhotos'][(string) $l['n']])) !== [];
            $mentions = array_values(array_filter([in_array('now', $publish, true) ? 'STOCK_ZERO' : null, $publish['web'] !== 'no' && !$photo ? 'NO_PHOTO' : null]));
            foreach ($group as $line) {
                $planned[$line['n']] = $pricing;
                $publishing[$line['n']] = ['publishPos' => $publish['pos'], 'publishWeb' => $publish['web'], 'mentions' => $mentions];
            }
        }
        // Articles existants dont le prix change : une décision par article, la même sur toutes ses lignes
        $newPrices = [];
        foreach ($byProduct as $productId => $productLines) {
            $decided = $rate !== null ? GestionInvoiceDraft::productPrice($productLines, $rate, $rounding) : null;
            foreach ($productLines as $line) {
                // prix avant et après de CHAQUE article, dans tous les cas ; gardé : le même, `changed` faux
                // le prix d'avant est celui de la LIGNE : celui de sa variante quand elle a un prix propre, sinon celui de l'article
                $current = $currentPrices[$line['n']] ?? $names[$productId]['price'];
                $newPrices[$line['n']] = $decided === null
                    ? ['mode' => 'keep', 'coefficient' => null, 'newPrice' => $current, 'currentPrice' => $current, 'changed' => false, 'belowCost' => false]
                    : ['mode' => $decided['mode'], 'coefficient' => $decided['coefficient'], 'newPrice' => $decided['price'], 'currentPrice' => $current,
                        'changed' => $decided['price'] !== $current, 'belowCost' => $decided['costConverted'] !== null && $decided['price'] < $decided['costConverted']];
            }
        }

        $out = [];
        $linesTotal = 0;
        foreach ($lines as $line) {
            $n = $line['n'];
            $total = $line['quantity'] !== null && $line['unitCost'] !== null ? $line['quantity'] * $line['unitCost'] : null;
            $linesTotal += $total ?? 0;
            $kind = $line['productId'] !== null && $line['create'] === null ? 'existing' : ($line['create'] !== null && $line['productId'] === null ? 'create' : 'unresolved');
            $converted = $rate !== null && $line['unitCost'] !== null ? GestionInvoiceDraft::convert($line['unitCost'], $rate) : null;
            $pricing = $kind === 'create' ? ($planned[$n] ?? null) : null;
            $newPrice = $kind === 'existing' ? ($newPrices[$n] ?? null) : null;
            $plannedPrice = $pricing['price'] ?? (($newPrice['mode'] ?? 'keep') !== 'keep' ? $newPrice['newPrice'] : null); // article créé : son prix ; article existant : son nouveau prix s'il est décidé
            $margin = $plannedPrice !== null && $converted !== null ? $plannedPrice - $converted : null;
            $out[] = [
                'n' => $n, 'quantity' => $line['quantity'], 'unitCost' => $line['unitCost'], 'total' => $total, 'unitCostConverted' => $converted, 'kind' => $kind,
                'coefficient' => $pricing['coefficient'] ?? ($newPrice['coefficient'] ?? null), 'plannedPrice' => $plannedPrice, 'currentPrice' => $kind === 'existing' ? ($currentPrices[$n] ?? null) : null,
                'plannedMargin' => $margin, 'plannedMarginRate' => $margin !== null && $plannedPrice > 0 ? round($margin / $plannedPrice * 100, 1) : null,
                'groupKey' => $kind === 'create' ? $line['create']['groupKey'] : null,
                'publishPos' => $publishing[$n]['publishPos'] ?? null, 'publishWeb' => $publishing[$n]['publishWeb'] ?? null, 'mentions' => $publishing[$n]['mentions'] ?? [],
                'price' => $newPrice,
            ] + ($target !== null ? ['lineBefore' => $lineAfter[$mergeKeys[$n] ?? '']['before'] ?? null, 'lineAfter' => $lineAfter[$mergeKeys[$n] ?? '']['after'] ?? null] : []);
        }

        // Totaux sur les lignes corrigées, dans la devise de la facture, au taux de taxe global lu sur le document (comme extraction.computed)
        $shippingAmount = 0;
        if (is_array($body['shipping'] ?? null)) {
            $shippingIn = new GestionInput($body['shipping']);
            $shippingAmount = $shippingIn->cents('amount') ?? 0;
            $issues += GestionInvoiceDraft::prefix('shipping', $shippingIn->errors());
        }
        $targetOut = null;
        if ($target !== null) {
            $current = $em->getRepository(\App\Entity\FraisDePort::class)->findOneBy(['commande' => $target]);
            $adds = is_array($body['shipping'] ?? null);
            if ($adds && $current !== null && ($body['shipping']['mode'] ?? null) !== 'add') {
                $issues['shipping'] = 'SHIPPING_COST_ALREADY_SET';
            }
            $targetOut = [
                'commandeId' => $target->getId(), 'name' => $target->getName(), 'date' => $target->getDate()?->format('Y-m-d'), 'currency' => $invoiceCurrency, 'exchangeRate' => $rate,
                'budgetBefore' => $target->getBudget(), 'budgetAfter' => ($target->getBudget() ?? 0) + $linesTotal + $shippingAmount,
                'shipping' => ['current' => $current?->getPrice(), 'added' => $adds ? $shippingAmount : 0, 'total' => ($current?->getPrice() ?? 0) + ($adds ? $shippingAmount : 0)],
            ];
        } elseif (isset($targetReason)) {
            $targetOut = ['commandeId' => is_numeric($targetIn['commandeId']) ? (int) $targetIn['commandeId'] : null, 'refused' => $targetReason];
        }
        $read = ['subtotal' => $extraction['totals']['subtotal'] ?? null, 'taxes' => $extraction['totals']['taxes'] ?? null, 'total' => $extraction['totals']['total'] ?? null];
        $mode = (string) ($extraction['amountsMode'] ?? 'unknown');
        // Taux exact de la lecture, recalculé depuis l'extraction (computed.taxRate en est l'arrondi à 5 décimales) : sur les lignes lues,
        // l'aperçu rend donc exactement extraction.computed
        $taxRate = $extraction !== [] ? GestionDocumentReader::taxRate($mode, is_array($extraction['taxes'] ?? null) ? $extraction['taxes'] : [], $read['subtotal'], $read['taxes'], $read['total']) : 0.0;
        $totals = GestionInvoiceDraft::totals($linesTotal, $shippingAmount, $taxRate, $mode, $read['total']);

        return [
            'currency' => $site, 'invoiceCurrency' => $invoiceCurrency, 'exchangeRate' => $rate,
            'pricesIncludeTax' => (bool) $em->getConnection()->fetchOne('SELECT prices_include_tax FROM entreprise ORDER BY id LIMIT 1'),
            'lines' => $out,
            'target' => $targetOut, // null : nouvelle commande ; sinon la commande à laquelle la facture s'ajoute (lot IA-FACT-2-A)
            'totals' => ['lines' => $linesTotal, 'shipping' => $shippingAmount, 'taxRate' => round($taxRate, 5), 'taxes' => $totals['taxes'], 'total' => $totals['total'], 'read' => $read, 'difference' => $totals['difference']],
            'issues' => array_map(fn (string $path, string $code) => ['path' => $path, 'code' => $code], array_keys($issues), array_values($issues)),
        ];
    }
}
