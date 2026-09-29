<?php

namespace App\Services\LandingAiService;

use App\Dto\LandingAiComposeInputDto;
use App\Entity\AiUsage;

/**
 * Exécute une demande déjà contrôlée et dont les crédits sont réservés : appel au moteur, puis consommation (succès)
 * ou libération (échec). Commun à la réponse synchrone (edit, create) et aux tâches de fond (page, images).
 */
final class LandingAiComposeRunner
{
    public function __construct(
        private readonly LandingAiComposer $composer,
        private readonly LandingAiQuotaService $quota
    ) {
    }

    /**
     * @return array corps de la réponse : { mode, composition | sections, dataType?, summary, warnings, credits, usage }
     * @throws LandingAiException crédits déjà libérés
     */
    public function run(LandingAiComposeInputDto $dto, AiUsage $usage): array
    {
        try {
            $result = match ($dto->mode) {
                'create' => $this->composer->create((string) $dto->componentKey, $dto->prompt, $dto->locale, $dto->media, $dto->dataType !== null ? (string) $dto->dataType : null, $dto->images),
                'page' => $this->composer->page($dto->prompt, $dto->locale, $dto->media, $dto->images, $dto->componentKey),
                default => $this->composer->edit((string) $dto->componentKey, $dto->composition, $dto->prompt, $dto->locale, $dto->media, $dto->images),
            };
        } catch (LandingAiException $e) {
            $this->quota->release($usage, $e->getStats());
            throw $e;
        } catch (\Throwable $e) {
            $this->quota->release($usage, null);
            throw $e;
        }

        $this->quota->complete($usage, $result->stats);

        $response = match ($dto->mode) {
            'page' => ['mode' => 'page', 'sections' => $result->sections],
            'create' => ['mode' => 'create', 'composition' => $result->composition, 'dataType' => $result->dataType],
            default => ['mode' => 'edit', 'composition' => $result->composition],
        };

        return $response + [
            'summary' => $result->summary,
            'warnings' => $result->warnings,
            'credits' => $this->quota->credits(),
            'usage' => $result->stats->toArray(),
        ];
    }

    /** Encodage des réponses : la composition garde ses {} et ses nombres décimaux (1.0) */
    public static function encode(array $body): string
    {
        return json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
