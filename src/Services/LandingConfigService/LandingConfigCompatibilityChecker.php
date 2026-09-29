<?php

namespace App\Services\LandingConfigService;

use App\Services\LandingPageSettingsService\ReglableCompositionScanner;
use App\Services\LandingPageSettingsService\ReglableCompositionValidator;

/**
 * Contrôle d'une version candidate avant activation (équivalent de app:landingpage:check-reglable) : toutes les
 * compositions enregistrées de tous les tenants, et les modèles du catalogue, doivent passer le nouveau schéma.
 */
final class LandingConfigCompatibilityChecker
{
    public const MAX_LISTED = 200;

    public function __construct(
        private readonly ReglableCompositionScanner $scanner,
        private readonly ReglableCompositionValidator $validator
    ) {
    }

    /**
     * @return array{checked: int, refusedCount: int, refused: list<array{tenants: string, database: string, path: string, message: string}>, skipped: list<array{database: string, reason: string}>}
     */
    public function check(string $schemaFile, string $catalogueFile): array
    {
        $validator = $this->validator->withSchemaFile($schemaFile);
        $checked = 0;
        $refused = [];
        $skipped = [];

        foreach ($this->scanner->scan() as $database) {
            if ($database['error'] !== null) {
                $skipped[] = ['database' => $database['database'], 'reason' => $database['error']];
                continue;
            }
            foreach ($database['compositions'] as $path => $composition) {
                $checked++;
                foreach ($validator->validateComposition($composition, $path) as $error) {
                    $refused[] = ['tenants' => implode(', ', $database['tenants']), 'database' => $database['database']] + $error;
                }
            }
        }

        $catalogue = json_decode((string) file_get_contents($catalogueFile), false);
        foreach (is_array($catalogue->families ?? null) ? $catalogue->families : [] as $family) {
            foreach (is_array($family->presets ?? null) ? $family->presets : [] as $preset) {
                $checked++;
                foreach ($validator->validateComposition($preset->composition ?? null, 'composition') as $error) {
                    $refused[] = ['tenants' => 'catalogue', 'database' => 'modèle ' . ($preset->id ?? '?')] + $error;
                }
            }
        }

        return ['checked' => $checked, 'refusedCount' => count($refused), 'refused' => array_slice($refused, 0, self::MAX_LISTED), 'skipped' => $skipped];
    }
}
