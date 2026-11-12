<?php

namespace App\Services\OrderService;

use App\Entity\Order;
use App\Entity\OrderTax;
use App\Entity\Tax;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\TenantEntityManagerProvider;

/**
 * Taxes d'une commande à sa création : TaxEngine avec l'adresse de livraison de la commande (même calcul que le devis
 * du panier, donc que le montant autorisé par Stripe). Une ligne OrderTax par taxe (rattachée à la ligne de la table
 * pour le fournisseur « table » ; sans rattachement pour Stripe Tax, dont la transaction est enregistrée après le
 * paiement). Jusqu'au 09/10/2026, toutes les taxes du site s'appliquaient quelle que soit l'adresse.
 */
class TaxCalculationService
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly TaxEngine $engine, private readonly TenantCurrencyProvider $currency, private readonly OrderTaxAddressResolver $taxAddress)
    {
    }

    /** @return float total des taxes (cents) */
    public function calculateTaxes(Order $order, float $subtotal, bool $persist = true): float
    {
        $em = $this->emProvider->getEntityManager();
        $taxes = $order->getPendingTaxes();
        $normalized = TaxEngine::normalizeAddress($this->taxAddress->forOrder($order)); // livraison, sinon adresse du site (comptoir)
        $jurisdiction = TaxCode::jurisdiction($normalized); // PAYS-1 : adresse de calcul gelée sur chaque taxe
        if ($taxes === null) {
            // Sans devis préalable (caisse, retour) : calcul sur le sous-total, livraison comprise
            $result = $this->engine->compute($normalized, [['amount' => (int) round(abs($subtotal)), 'quantity' => 1, 'kind' => 'sale', 'reference' => 'order']], 0, $this->currency->code());
            $order->setPendingTaxCalculationId($result['calculationId']);
            $taxes = $result['taxes'];
        }
        $sign = $subtotal < 0 ? -1 : 1; // retour : taxes négatives, comme le sous-total

        $totalTax = 0.0;
        foreach ($taxes as $tax) {
            $tax['amount'] = $sign * (int) $tax['amount'];
            $totalTax += $tax['amount'];
            $orderTax = (new OrderTax())->setOrderTax($order)->setAmount((float) $tax['amount'])->setBase(isset($tax['base']) ? $sign * (int) $tax['base'] : null); // assiette HT (F18 partie 2a)
            $row = isset($tax['taxId']) ? $em->getRepository(Tax::class)->find($tax['taxId']) : null;
            if ($row !== null) {
                $orderTax->setTax($row);
            }
            // PAYS-1 : taxe gelée telle qu'appliquée (table : taxId ; Stripe Tax : libellé, taux et juridiction de Stripe, sans ligne tax)
            $label = (string) ($tax['label'] ?? $row?->getName() ?? 'Taxe');
            $orderTax->freeze(['label' => $label, 'ratePpm' => TaxCode::ppm(isset($tax['rate']) ? (float) $tax['rate'] : ($row ? (float) $row->getRate() : null)),
                'code' => $row ? TaxCode::of($row) : TaxCode::fromLabel($label), 'jurisdiction' => isset($tax['taxId']) ? $jurisdiction : (TaxCode::stripeJurisdiction($tax['jurisdiction'] ?? null) ?? $jurisdiction),
                'category' => array_key_exists('category', $tax) ? $tax['category'] : $row?->getCategory(), 'pricesIncludeTax' => $order->isPricesIncludeTax(), 'source' => isset($tax['taxId']) ? 'table' : 'stripe']);
            $order->addOrderTax($orderTax);
            if ($persist) {
                $em->persist($orderTax);
            }
        }

        return $totalTax;
    }
}
