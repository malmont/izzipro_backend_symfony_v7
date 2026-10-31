<?php

namespace App\Services\AppProfileService;

use App\Entity\AppProfile;
use App\Repository\AppProfileRepository;
use App\Services\BoutiqueSettingsService\BoutiqueSettingsService;
use App\Services\TenantEntityManagerProvider;

/**
 * Thème d'une application du site (31/10/2026), lu par l'application e-commerce mobile (GET /api/mobile/app-theme).
 * Chaque champ est pris, dans l'ordre : dans le profil de l'application (app_profile, réglé dans EasyAdmin), sinon
 * dans la charte de la boutique web (boutique-settings, « charter » : couleurs, headingFont si la police est dans la
 * liste, radius borné, buttonStyle), sinon null (l'application applique son défaut). La fiche entreprise n'a pas de
 * couleur : le troisième repli de primaryColor est donc null aujourd'hui. `sources` dit d'où vient chaque valeur.
 */
class AppThemeService
{
    /** Champs du thème, dans l'ordre de la réponse */
    public const FIELDS = ['primaryColor', 'accentColor', 'mode', 'font', 'radius', 'buttonStyle', 'productCard', 'homeHero'];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly BoutiqueSettingsService $boutique)
    {
    }

    /** @return array{app: string, theme: array<string, mixed>, sources: array<string, ?string>} */
    /** Seul code d'application dont le thème se replie sur la charte de la boutique web */
    public const CHARTER_APP = 'mobile';

    public function resolve(string $app): array
    {
        $profile = $this->repository()->findOneByApp($app);
        // Repli sur la charte de la boutique web réservé à l'application e-commerce mobile (décision du 31/10/2026) :
        // pour les autres codes, un réglage absent reste null (l'application applique son propre défaut)
        $charter = $app === self::CHARTER_APP ? $this->boutique->charter() : [];

        // Valeurs lues telles qu'enregistrées, puis re-contrôlées (une écriture hors formulaire pourrait les fausser)
        $own = [
            'primaryColor' => self::hexColor($profile?->getPrimaryColor()),
            'accentColor' => self::hexColor($profile?->getAccentColor()),
            'mode' => self::oneOf($profile?->getThemeMode(), AppProfile::THEME_MODES),
            'font' => self::knownFont($profile?->getThemeFont()),
            'radius' => self::boundedRadius($profile?->getThemeRadius()),
            'buttonStyle' => self::oneOf($profile?->getButtonStyle(), AppProfile::BUTTON_STYLES),
            'productCard' => self::oneOf($profile?->getProductCard(), AppProfile::PRODUCT_CARDS),
            'homeHero' => self::oneOf($profile?->getHomeHero(), AppProfile::HOME_HEROS),
        ];
        $fromCharter = [
            'primaryColor' => self::hexColor($charter['primaryColor'] ?? null),
            'accentColor' => self::hexColor($charter['accentColor'] ?? null),
            'font' => self::knownFont($charter['headingFont'] ?? null),
            'radius' => self::boundedRadius($charter['radius'] ?? null),
            'buttonStyle' => self::oneOf($charter['buttonStyle'] ?? null, AppProfile::BUTTON_STYLES),
        ];

        $theme = [];
        $sources = [];
        foreach (self::FIELDS as $field) {
            if ($own[$field] !== null) {
                $theme[$field] = $own[$field];
                $sources[$field] = 'app';
            } elseif (($fromCharter[$field] ?? null) !== null) {
                $theme[$field] = $fromCharter[$field];
                $sources[$field] = 'charter';
            } else {
                $theme[$field] = null;
                $sources[$field] = null;
            }
        }

        // Dernier changement possible du thème : le profil, et la charte quand elle fournit au moins un champ
        $updatedAt = $profile?->getUpdatedAt();
        if (in_array('charter', $sources, true)) {
            $charterAt = $this->boutique->lastEditorChangeAt();
            if ($charterAt !== null && ($updatedAt === null || $charterAt > $updatedAt)) {
                $updatedAt = $charterAt;
            }
        }
        $theme['updatedAt'] = $updatedAt?->format(\DateTimeInterface::ATOM);

        return ['app' => $app, 'theme' => $theme, 'sources' => $sources];
    }

    /**
     * Couleur ramenée au format #rrggbb (minuscules) : #rgb et #rrggbbaa (alpha ignoré) de la charte, rgb()/rgba()
     * convertis ; transparent ou autre chose → null.
     */
    public static function hexColor(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));
        if (preg_match('/^#([0-9a-f]{6})([0-9a-f]{2})?$/', $value, $m)) {
            return '#' . $m[1];
        }
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])([0-9a-f])?$/', $value, $m)) {
            return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        }
        if (preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*[\d.]+\s*)?\)$/', $value, $m)) {
            [$r, $g, $b] = [min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3])];

            return sprintf('#%02x%02x%02x', $r, $g, $b);
        }

        return null;
    }

    /** Police de la liste fermée (comparaison sans la casse), sous son nom canonique ; sinon null */
    public static function knownFont(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $wanted = strtolower(trim($value));
        foreach (AppProfile::THEME_FONTS as $font) {
            if (strtolower($font) === $wanted) {
                return $font;
            }
        }

        return null;
    }

    /**
     * Arrondi en entier de 0 à AppProfile::RADIUS_MAX : nombre (de la charte : 0 à 100) ou taille CSS en px, rem ou em
     * (1 rem = 16 px) ramenés à la borne ; un pourcentage ou une valeur illisible → null.
     */
    public static function boundedRadius(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            $px = (float) $value;
        } elseif (is_string($value) && preg_match('/^\s*(\d{1,3}(?:\.\d+)?)\s*(px|rem|em)?\s*$/i', $value, $m)) {
            $px = (float) $m[1] * (isset($m[2]) && strtolower($m[2]) !== 'px' ? 16 : 1);
        } else {
            return null;
        }
        if ($px < 0) {
            return null;
        }

        return (int) min(AppProfile::RADIUS_MAX, round($px));
    }

    private static function oneOf(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    private function repository(): AppProfileRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(AppProfile::class);
    }
}
