<?php

namespace App\LossPrevention\Services;

use App\LossPrevention\Master\LossHardwareProductRepository;

/**
 * Gamme de matériel des étiquettes à puce (lot L2-b, 15/11/2026, `docs/prevention-pertes.md` § 13.10) : pour chaque besoin, trois
 * niveaux (pro, standard, bricoleur), avec ce qu'il faut, ce que ça permet et ses limites. Des textes servis par le serveur, en
 * français et en anglais. Le niveau bricoleur se vend sans matériel (décision du propriétaire du 15/11/2026). Le modèle ne dépend pas du
 * niveau : une puce est un identifiant et une technologie.
 *
 * Lot L6-a (17/11/2026, § 19) : le matériel vendu par la plateforme (`products`, lus dans la base maître, actifs seulement), avec son
 * prix et le lien de sa fiche dans la boutique de forfaits ; `levels[].forSale` dit si un article de ce niveau est en vente.
 */
class LossHardwareCatalog
{
    public const NEEDS = ['read', 'print', 'attach'];
    public const LEVELS = ['pro', 'standard', 'diy'];
    /** Technologie de puce conseillée à chaque niveau, pour « lire une puce » */
    private const TECHNOLOGY = ['pro' => LossTagIdentifier::UHF, 'standard' => LossTagIdentifier::UHF, 'diy' => LossTagIdentifier::NFC];

    public function __construct(private readonly LossTexts $texts, private readonly ?LossHardwareProductRepository $products = null)
    {
    }

    /**
     * @return array<string, mixed> { needs [{ code, label, levels [{ level, label, technology, what, allows, limits }] }], levels [{ code, label,
     *                              summary, soldWithHardware, forSale }], products [{ code, kind, level, label, description, price { amountCents,
     *                              currency, display } | null, buyUrl | null }], note, screen }
     */
    public function catalogue(string $locale): array
    {
        $needs = [];
        foreach (self::NEEDS as $need) {
            $levels = [];
            foreach (self::LEVELS as $level) {
                $levels[] = [
                    'level' => $level,
                    'label' => $this->texts->t("hardware.levels.$level.label", $locale),
                    'technology' => $need === 'read' ? self::TECHNOLOGY[$level] : null,
                    'what' => $this->texts->t("hardware.needs.$need.$level.what", $locale),
                    'allows' => $this->texts->t("hardware.needs.$need.$level.allows", $locale),
                    'limits' => $this->texts->t("hardware.needs.$need.$level.limits", $locale),
                ];
            }
            $needs[] = ['code' => $need, 'label' => $this->texts->t("hardware.needs.$need.label", $locale), 'levels' => $levels];
        }
        $products = $this->forSale($locale);
        $levels = [];
        foreach (self::LEVELS as $level) {
            $levels[] = ['code' => $level, 'label' => $this->texts->t("hardware.levels.$level.label", $locale), 'summary' => $this->texts->t("hardware.levels.$level.summary", $locale),
                'soldWithHardware' => $level !== 'diy', 'forSale' => in_array($level, array_column($products, 'level'), true)];
        }

        return ['needs' => $needs, 'levels' => $levels, 'products' => $products, 'note' => $this->texts->t('hardware.note', $locale), 'screen' => LossInstallationService::SCREEN];
    }

    /**
     * Le matériel en vente (actif, donc avec un prix), dans la langue demandée.
     *
     * @return list<array<string, mixed>>
     */
    private function forSale(string $locale): array
    {
        $out = [];
        foreach ($this->products?->all(true) ?? [] as $product) {
            if ($product['priceCents'] === null) {
                continue; // la base l'interdit déjà : en ceinture
            }
            $en = $locale === 'en';
            $out[] = ['code' => $product['code'], 'kind' => $product['kind'], 'level' => $product['level'],
                'label' => $en ? $product['labelEn'] : $product['labelFr'], 'description' => $en ? $product['descriptionEn'] : $product['descriptionFr'],
                'price' => ['amountCents' => $product['priceCents'], 'currency' => $product['currency'], 'display' => LossTexts::money($product['priceCents'], $locale, $product['currency'])],
                'buyUrl' => $product['buyUrl']];
        }

        return $out;
    }
}
