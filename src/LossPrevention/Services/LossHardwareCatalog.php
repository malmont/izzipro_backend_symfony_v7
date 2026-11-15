<?php

namespace App\LossPrevention\Services;

/**
 * Gamme de matériel des étiquettes à puce (lot L2-b, 15/11/2026, `docs/prevention-pertes.md` § 13.10) : pour chaque besoin, trois
 * niveaux (pro, standard, bricoleur), avec ce qu'il faut, ce que ça permet et ses limites. Des textes servis par le serveur, en
 * français et en anglais ; ni prix ni vente ici (l'offre et la vente du matériel sont le lot L6). Le niveau bricoleur se vend sans
 * matériel (décision du propriétaire du 15/11/2026). Le modèle ne dépend pas du niveau : une puce est un identifiant et une technologie.
 */
class LossHardwareCatalog
{
    public const NEEDS = ['read', 'print', 'attach'];
    public const LEVELS = ['pro', 'standard', 'diy'];
    /** Technologie de puce conseillée à chaque niveau, pour « lire une puce » */
    private const TECHNOLOGY = ['pro' => LossTagIdentifier::UHF, 'standard' => LossTagIdentifier::UHF, 'diy' => LossTagIdentifier::NFC];

    public function __construct(private readonly LossTexts $texts)
    {
    }

    /** @return array<string, mixed> { needs [{ code, label, levels [{ level, label, technology, what, allows, limits }] }], levels [{ code, label, summary, soldWithHardware }], note, screen } */
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
        $levels = [];
        foreach (self::LEVELS as $level) {
            $levels[] = ['code' => $level, 'label' => $this->texts->t("hardware.levels.$level.label", $locale), 'summary' => $this->texts->t("hardware.levels.$level.summary", $locale),
                'soldWithHardware' => $level !== 'diy'];
        }

        return ['needs' => $needs, 'levels' => $levels, 'note' => $this->texts->t('hardware.note', $locale), 'screen' => LossInstallationService::SCREEN];
    }
}
