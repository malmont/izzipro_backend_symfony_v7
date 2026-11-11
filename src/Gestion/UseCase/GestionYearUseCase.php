<?php

namespace App\Gestion\UseCase;

use App\Gestion\Services\GestionYearService;

/** L'année de l'entreprise (lot AN, `docs/annee-gestion.md`) : séries, rentabilité, leviers, bilan et avis d'une année terminée */
class GestionYearUseCase
{
    public function __construct(private readonly GestionYearService $years)
    {
    }

    public function overview(array $query, string $locale): array
    {
        return $this->years->overview($query, $locale);
    }

    public function advise(array $query, string $locale, ?string $user): array
    {
        return $this->years->advise($query, $locale, $user);
    }
}
