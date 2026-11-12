<?php

namespace App\Gestion\UseCase;

use App\Gestion\Services\GestionDayService;

/** « Ma journée » (lot JOUR, `docs/journee-gestion.md`) */
class GestionDayUseCase
{
    public function __construct(private readonly GestionDayService $day)
    {
    }

    public function overview(): array
    {
        return $this->day->overview();
    }
}
