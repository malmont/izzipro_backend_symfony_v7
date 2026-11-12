<?php

namespace App\Gestion\Services\Document;

use App\Gestion\Services\GestionInput;

/**
 * Facture fournisseur en cours de vérification (lot APERÇU-FACT, 12/11/2026, `docs/lecture-documents-gestion.md` § 4 bis) : lecture des
 * lignes corrigées par le gérant et calculs, partagés par l'aperçu (`PreviewGestionDocumentUseCase`) et la confirmation
 * (`ConfirmGestionDocumentUseCase`). Une seule fonction donne le prix d'un article créé : l'aperçu et la confirmation du même corps
 * rendent donc le même prix. Fonctions pures, aucune lecture ni écriture en base.
 */
final class GestionInvoiceDraft
{
    public const DEFAULT_COEFFICIENT = 2.0;

    /**
     * Lignes du corps, validées champ par champ (mêmes chemins et mêmes codes pour l'aperçu et la confirmation).
     *
     * @param array<string, mixed> $body
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>} lignes lues, erreurs (chemin → code)
     */
    public static function parseLines(array $body): array
    {
        $errors = [];
        $lines = [];
        foreach (array_values(is_array($body['lines'] ?? null) ? $body['lines'] : []) as $i => $line) {
            $n = is_array($line) && isset($line['n']) && is_numeric($line['n']) ? (int) $line['n'] : 1000 + $i;
            $lineInput = new GestionInput(is_array($line) ? $line : []);
            $quantity = $lineInput->integer('quantity');
            $unitCost = $lineInput->cents('unitCost');
            $productId = $lineInput->id('productId', false);
            $variantId = $lineInput->id('variantId', false);
            $label = $lineInput->text('label', false);
            $size = $lineInput->text('size', false, 40);
            $color = $lineInput->text('color', false, 40);
            $errors += self::prefix("lines[$n]", $lineInput->errors());
            if ($quantity !== null && $quantity < 1) {
                $errors["lines[$n].quantity"] = 'MUST_BE_POSITIVE';
            }
            $create = is_array($line) && is_array($line['create'] ?? null) ? $line['create'] : null;
            if ($productId === null && $create === null) {
                $errors["lines[$n]"] = 'LINE_UNRESOLVED';
            }
            if ($productId !== null && $create !== null) {
                $errors["lines[$n]"] = 'PRODUCT_OR_CREATE';
            }
            $createName = null;
            $coefficient = null;
            $groupKey = null;
            if ($create !== null) {
                $createInput = new GestionInput($create);
                $createName = $createInput->text('name');
                $coefficient = $createInput->decimal('coefficient', false);
                $groupKey = $createInput->text('groupKey', false, 120);
                $errors += self::prefix("lines[$n].create", $createInput->errors());
                if ($coefficient !== null && $coefficient <= 0) {
                    $errors["lines[$n].create.coefficient"] = 'MUST_BE_POSITIVE';
                }
            }
            $lines[] = ['n' => $n, 'label' => $label, 'quantity' => $quantity, 'unitCost' => $unitCost, 'productId' => $productId, 'variantId' => $variantId, 'size' => $size, 'color' => $color,
                'create' => $create !== null ? ['name' => $createName, 'coefficient' => $coefficient, 'groupKey' => $groupKey] : null];
        }
        if ($lines === []) {
            $errors['lines'] = 'REQUIRED';
        }

        return [$lines, $errors];
    }

    /**
     * Lignes à créer, regroupées : un article par `groupKey` (variantes), sinon un article par ligne.
     *
     * @param list<array<string, mixed>> $lines
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function groups(array $lines): array
    {
        $groups = [];
        foreach ($lines as $line) {
            if ($line['create'] === null || $line['productId'] !== null) {
                continue;
            }
            $groups[$line['create']['groupKey'] ?? ('line-' . $line['n'])][] = $line;
        }

        return $groups;
    }

    /**
     * Taux qui s'applique aux coûts de la facture pour les lire dans la devise du site : 1 quand le fournisseur n'a pas de devise ou a
     * celle du site (un taux envoyé est alors ignoré, comme sur la commande : lot G6) ; sinon le taux saisi ; null s'il manque (la
     * confirmation refuse alors : `exchangeRate` `EXCHANGE_RATE_REQUIRED`).
     */
    public static function effectiveRate(?string $purchaseCurrency, string $siteCurrency, ?float $exchangeRate): ?float
    {
        if ($purchaseCurrency === null || strtoupper($purchaseCurrency) === strtoupper($siteCurrency)) {
            return 1.0;
        }

        return $exchangeRate !== null && $exchangeRate > 0 ? $exchangeRate : null;
    }

    /** Coût en cents de la devise de la facture → cents de la devise du site */
    public static function convert(int $cost, float $rate): int
    {
        return (int) round($cost * $rate);
    }

    /**
     * Prix d'un article créé par un groupe de lignes, LA règle (aperçu et confirmation) : coût moyen pondéré du groupe, converti dans la
     * devise du site, × coefficient de la première ligne (2 par défaut). Le coefficient se lit donc « prix de vente ÷ coût, dans la devise
     * du site ». `cost` reste dans la devise de la facture (ligne d'achat, lot G6) ; `costConverted` est le prix d'achat de la fiche.
     *
     * @param list<array<string, mixed>> $group lignes de même groupKey, quantités et coûts valides
     *
     * @return array{cost: int, costConverted: int, coefficient: float, price: int}
     */
    public static function groupPrice(array $group, float $rate): array
    {
        $quantity = array_sum(array_column($group, 'quantity'));
        $cost = $quantity > 0 ? (int) round(array_sum(array_map(fn ($l) => $l['quantity'] * $l['unitCost'], $group)) / $quantity) : (int) $group[0]['unitCost'];
        $coefficient = (float) ($group[0]['create']['coefficient'] ?? self::DEFAULT_COEFFICIENT);
        $converted = self::convert($cost, $rate);

        return ['cost' => $cost, 'costConverted' => $converted, 'coefficient' => $coefficient, 'price' => (int) round($converted * $coefficient)];
    }

    /**
     * Total recalculé d'une facture (lecture et aperçu) : lignes + port, taxes au taux global du document (aucune quand le mode des
     * montants est inconnu), et écart avec le total lu. Montants dans la devise de la facture.
     *
     * @return array{taxes: int, total: int, difference: ?int}
     */
    public static function totals(int $linesTotal, int $shipping, float $taxRate, string $amountsMode, ?int $totalRead): array
    {
        $taxes = (int) round(($linesTotal + $shipping) * ($taxRate > 0 && $amountsMode !== 'unknown' ? $taxRate : 0));
        $total = $linesTotal + $shipping + $taxes;

        return ['taxes' => $taxes, 'total' => $total, 'difference' => $totalRead !== null ? $totalRead - $total : null];
    }

    /**
     * @param array<string, string> $errors
     *
     * @return array<string, string>
     */
    public static function prefix(string $prefix, array $errors): array
    {
        $out = [];
        foreach ($errors as $field => $code) {
            $out[$prefix . '.' . $field] = $code;
        }

        return $out;
    }
}
