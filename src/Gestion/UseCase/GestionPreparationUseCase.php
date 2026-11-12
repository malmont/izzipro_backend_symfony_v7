<?php

namespace App\Gestion\UseCase;

use App\Gestion\Services\GestionPreparationAdviceService;
use App\Gestion\Services\GestionPreparationService;

/** Préparer une période (lot P6, `docs/preparation-periode.md`) : estimations, simulation, avis du guide, valider. */
class GestionPreparationUseCase
{
    public function __construct(private readonly GestionPreparationService $preparation, private readonly GestionPreparationAdviceService $advice)
    {
    }

    public function estimations(mixed $periodId, mixed $date, string $locale = 'fr'): array
    {
        return $this->preparation->estimations($this->preparation->period($periodId, $date), null, $locale);
    }

    public function simulate(array $body): array
    {
        return $this->preparation->simulate($body);
    }

    public function advise(array $body, string $locale, ?string $user): array
    {
        return $this->advice->advise($body, $locale, $user);
    }

    public function validate(array $body, ?string $user): array
    {
        return $this->preparation->validate($body, $user);
    }
}
