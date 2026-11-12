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
 */
class PreviewGestionDocumentUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly GestionDocumentService $documents,
        private readonly TenantCurrencyProvider $currency,
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
        $rate = GestionInvoiceDraft::effectiveRate($invoiceCurrency, $site, $exchangeRate);
        if ($rate === null) {
            $issues['exchangeRate'] = 'EXCHANGE_RATE_REQUIRED';
        }

        // Articles existants : prix de vente actuel (la confirmation ne le change pas)
        $currentPrices = [];
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
        }

        // Articles à créer : un prix par groupe, répété sur chaque ligne du groupe ; aucun si une ligne du groupe est refusée
        $planned = [];
        foreach (GestionInvoiceDraft::groups($lines) as $group) {
            $valid = $rate !== null;
            foreach ($group as $line) {
                foreach (array_keys($issues) as $path) {
                    $valid = $valid && $path !== "lines[{$line['n']}]" && !str_starts_with($path, "lines[{$line['n']}].");
                }
            }
            $pricing = $valid ? GestionInvoiceDraft::groupPrice($group, $rate) : null;
            foreach ($group as $line) {
                $planned[$line['n']] = $pricing;
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
            $plannedPrice = $pricing['price'] ?? null;
            $margin = $plannedPrice !== null && $converted !== null ? $plannedPrice - $converted : null;
            $out[] = [
                'n' => $n, 'quantity' => $line['quantity'], 'unitCost' => $line['unitCost'], 'total' => $total, 'unitCostConverted' => $converted, 'kind' => $kind,
                'coefficient' => $pricing['coefficient'] ?? null, 'plannedPrice' => $plannedPrice, 'currentPrice' => $kind === 'existing' ? ($currentPrices[$n] ?? null) : null,
                'plannedMargin' => $margin, 'plannedMarginRate' => $margin !== null && $plannedPrice > 0 ? round($margin / $plannedPrice * 100, 1) : null,
                'groupKey' => $kind === 'create' ? $line['create']['groupKey'] : null,
            ];
        }

        // Totaux sur les lignes corrigées, dans la devise de la facture, au taux de taxe global lu sur le document (comme extraction.computed)
        $shippingAmount = 0;
        if (is_array($body['shipping'] ?? null)) {
            $shippingIn = new GestionInput($body['shipping']);
            $shippingAmount = $shippingIn->cents('amount') ?? 0;
            $issues += GestionInvoiceDraft::prefix('shipping', $shippingIn->errors());
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
            'totals' => ['lines' => $linesTotal, 'shipping' => $shippingAmount, 'taxRate' => round($taxRate, 5), 'taxes' => $totals['taxes'], 'total' => $totals['total'], 'read' => $read, 'difference' => $totals['difference']],
            'issues' => array_map(fn (string $path, string $code) => ['path' => $path, 'code' => $code], array_keys($issues), array_values($issues)),
        ];
    }
}
