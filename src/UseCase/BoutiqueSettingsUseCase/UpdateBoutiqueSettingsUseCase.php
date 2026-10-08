<?php

namespace App\UseCase\BoutiqueSettingsUseCase;

use App\Dto\BoutiqueSettingsInputDto;
use App\Services\BoutiqueSettingsService\BoutiqueConfigurationValidator;
use App\Services\BoutiqueSettingsService\BoutiqueSettingsException;
use App\Services\BoutiqueSettingsService\BoutiqueSettingsService;
use App\Services\ContentAuditService\ContentAuditRecorder;

/**
 * PUT /api/boutique-settings : contrôle (compositions, pages système, charte, commerce), enregistrement de la
 * configuration complète, puis inscription au journal (resource « boutique-settings », état avant / après et clés
 * de premier niveau modifiées). Rien n'est enregistré si une erreur est trouvée.
 */
class UpdateBoutiqueSettingsUseCase
{
    public function __construct(
        private readonly BoutiqueSettingsService $service,
        private readonly BoutiqueConfigurationValidator $validator,
        private readonly ContentAuditRecorder $audit
    ) {
    }

    /** @throws BoutiqueSettingsException 422 */
    public function execute(BoutiqueSettingsInputDto $dto): void
    {
        $errors = $this->validator->validateConfiguration($dto->configuration);
        if ($errors) {
            throw new BoutiqueSettingsException(422,
                sprintf('%s : %s', $errors[0]['path'] !== '' ? $errors[0]['path'] : 'configuration', $errors[0]['message']) . (count($errors) > 1 ? sprintf(' (et %d autre(s) erreur(s))', count($errors) - 1) : ''),
                $errors);
        }

        $setting = $this->service->findOrCreateSettings();
        $before = $this->service->getRawConfiguration($setting);
        $this->service->updateSettings($setting, get_object_vars($dto->configuration)); // sous-objets conservés tels quels

        $old = json_decode((string) $before, true) ?? [];
        $new = json_decode($dto->json, true) ?? [];
        $changed = array_values(array_filter(array_unique([...array_keys($old), ...array_keys($new)]), fn ($key) => ($old[$key] ?? null) != ($new[$key] ?? null)));
        if ($changed || $before === null) {
            $this->audit->record('boutique-settings', $setting->getId(), 'update', $before, $dto->json, $changed);
        }
    }
}
