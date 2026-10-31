<?php

namespace App\Services\AppProfileService;

use App\Entity\AppProfile;
use App\Services\TenantConnectionProvider;
use App\Services\TenantPublicHostResolver;

/**
 * Aperçu en direct de l'apparence dans EasyAdmin (B3, 31/10/2026) : gabarits HTML autonomes fournis par chaque
 * application (resources/apercu/apercu-apparence-<code>.html, contrat window.ApercuApparence = {app, update(theme, profil)},
 * ?embed=1 = appareil seul), servis par GET /admin/apercu/{code}. Ce service dit quels gabarits existent et produit
 * le JSON posé dans le formulaire : repli des champs vides (charte du site pour le seul code mobile, null sinon) et profil.
 */
class AppThemePreviewService
{
    public const DIR = 'resources/apercu';
    public const FILE_PATTERN = 'apercu-apparence-%s.html';
    public const UNAVAILABLE = 'Aperçu non disponible pour cette application';

    public function __construct(
        private readonly AppThemeService $themes,
        private readonly AppProfileService $profiles,
        private readonly TenantConnectionProvider $tenant,
        private readonly TenantPublicHostResolver $hosts,
        private readonly string $projectDir,
    ) {
    }

    /** Codes d'application qui ont un gabarit (fichier présent), triés */
    public function templates(): array
    {
        $codes = [];
        foreach (glob($this->projectDir . '/' . self::DIR . '/' . sprintf(self::FILE_PATTERN, '*')) ?: [] as $file) {
            if (preg_match('/apercu-apparence-([a-z]+)\.html$/', $file, $m) && isset(AppProfile::APPS[$m[1]])) {
                $codes[] = $m[1];
            }
        }
        sort($codes);

        return $codes;
    }

    /** Chemin du gabarit d'un code, null s'il n'en a pas (code hors liste compris) */
    public function templatePath(string $code): ?string
    {
        if (!\in_array($code, $this->templates(), true)) {
            return null;
        }

        return $this->projectDir . '/' . self::DIR . '/' . sprintf(self::FILE_PATTERN, $code);
    }

    /** Adresse du cadre d'aperçu d'un code, null sans gabarit */
    public function embedUrl(string $code): ?string
    {
        return $this->templatePath($code) !== null ? sprintf('/admin/apercu/%s?embed=1', $code) : null;
    }

    /**
     * Données du formulaire d'un profil : gabarits disponibles, adresse du cadre (null sans gabarit), repli de chaque
     * champ (charte du site pour le code mobile, null pour les autres), nom et logo du profil tels que l'application les reçoit.
     *
     * @return array{app: ?string, templates: string[], url: ?string, unavailable: string, fallback: array<string, mixed>, fallbackMobile: array<string, mixed>, profil: array{name: ?string, logo: ?string}}
     */
    public function payload(?string $app): array
    {
        $app = $app === null || trim($app) === '' ? null : trim($app); // fiche en création : code pas encore choisi
        $nulls = array_fill_keys(AppThemeService::FIELDS, null);
        $fromCharter = $nulls;
        try {
            $fromCharter = $this->themes->charterTheme() + $nulls;
        } catch (\Throwable) {
            // site sans réglages de boutique : aucun repli
        }
        $profil = ['name' => null, 'logo' => null];
        if ($app !== null) {
            try {
                $host = $this->hosts->describe((string) $this->tenant->getTenantCode())['host'] ?? (string) $this->tenant->getTenantCode();
                $resolved = $this->profiles->resolve($app, $host);
                $profil = ['name' => $resolved['name'] ?? null, 'logo' => $resolved['logo'] ?? null];
            } catch (\Throwable) {
                // profil illisible : l'aperçu montre un nom vide
            }
        }

        return [
            'app' => $app,
            'templates' => $this->templates(),
            'url' => $app !== null ? $this->embedUrl($app) : null,
            'unavailable' => self::UNAVAILABLE,
            'fallback' => $app === AppThemeService::CHARTER_APP ? $fromCharter : $nulls,
            'fallbackMobile' => $fromCharter,
            'profil' => $profil,
        ];
    }
}
