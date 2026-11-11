<?php

namespace App\UseCase\GestionUseCase;

use App\Services\GestionService\GestionFiscalYearService;

/** Exercice du site (lot AN, partie EX) : réglage du mois de début */
class GestionFiscalYearUseCase
{
    public function __construct(private readonly GestionFiscalYearService $fiscal)
    {
    }

    public function settings(): array
    {
        return $this->fiscal->settings();
    }

    public function put(array $body): array
    {
        return $this->fiscal->put($body);
    }
}
