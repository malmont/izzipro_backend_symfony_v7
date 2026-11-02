<?php

namespace App\Dto;

use App\Entity\Entreprise;

/** En-tête « magasin » des tickets et rapports de caisse (fiche entreprise) : {name, address, phone, taxNumber} */
class StoreInfoDto
{
    public static function fromEntreprise(?Entreprise $entreprise, string $locale = 'fr'): ?array
    {
        if (!$entreprise) {
            return null;
        }
        $address = $entreprise->getAddressEntreprise();

        return [
            'name' => $entreprise->getName(),
            'address' => $address
                ? trim(implode(', ', array_filter([$address->getStreet1(), $address->getStreet2(), trim(($address->getZip() ?? '') . ' ' . ($address->getCity() ?? '')), $address->getState(), $address->getCountry()])))
                : $entreprise->getAdress(),
            'phone' => $entreprise->getTel(),
            // Lot R1 (23/10/2026) : numéros de taxes masquables, numéros de TPS et TVQ, en-tête et pied du ticket (ajouts)
            'taxNumber' => $entreprise->isPosReceiptShowTaxNumbers() ? ($entreprise->getTvaIntracommunautaire() ?: $entreprise->getEin()) : null,
            'taxNumbers' => $entreprise->isPosReceiptShowTaxNumbers() ? ['gst' => $entreprise->getGstNumber(), 'qst' => $entreprise->getQstNumber()] : null,
            'showTaxNumbers' => $entreprise->isPosReceiptShowTaxNumbers(),
            // F18 partie 2a : identifiants dans l'ordre d'impression selon le pays du magasin (FR : SIRET sinon ein, TVA intracom. ; CA : TPS, TVQ ; US : EIN)
            'taxIds' => $entreprise->isPosReceiptShowTaxNumbers() ? self::taxIds($entreprise, $address?->getCountry(), $locale) : [],
            'receiptHeader' => $entreprise->getPosReceiptHeader(),
            'receiptFooter' => $entreprise->getPosReceiptFooter(),
        ];
    }

    /** @return list<array{label: string, number: string}> identifiants fiscaux renseignés, dans l'ordre d'impression du pays, libellés dans la langue du site (fr | en) */
    public static function taxIds(Entreprise $e, ?string $country, string $locale = 'fr'): array
    {
        $en = str_starts_with(strtolower($locale), 'en');
        $gst = $en ? 'GST' : 'TPS';
        $qst = $en ? 'QST' : 'TVQ';
        $vat = $en ? 'VAT no.' : 'TVA intracom.';
        $country = strtoupper(trim((string) $country));
        $country = match ($country) { 'CANADA' => 'CA', 'FRANCE' => 'FR', 'ÉTATS-UNIS', 'ETATS-UNIS', 'USA', 'UNITED STATES' => 'US', default => $country };
        $siret = $e->getSiret() ?: $e->getEin(); // un site français a pu saisir son SIRET dans ein (étiqueté « SIRET » jusqu'au 01/11/2026)
        $candidates = match ($country) {
            'FR' => [['SIRET', $siret], [$vat, $e->getTvaIntracommunautaire()]],
            'CA' => [[$gst, $e->getGstNumber()], [$qst, $e->getQstNumber()]],
            'US' => [['EIN', $e->getEin()]],
            default => [[$vat, $e->getTvaIntracommunautaire()], ['SIRET', $e->getSiret()], ['EIN', $e->getEin()], [$gst, $e->getGstNumber()], [$qst, $e->getQstNumber()]],
        };
        $out = [];
        foreach ($candidates as [$label, $number]) {
            if (is_string($number) && trim($number) !== '') {
                $out[] = ['label' => $label, 'number' => trim($number)];
            }
        }

        return $out;
    }
}
