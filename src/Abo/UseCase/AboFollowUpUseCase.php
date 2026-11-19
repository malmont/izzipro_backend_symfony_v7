<?php

namespace App\Abo\UseCase;

use App\Abo\Services\AboFollowUpService;

/** Lectures de suivi des abonnements (lot ABO-0) : la langue de la requête, l'identité de l'abonné rendue à l'administrateur */
class AboFollowUpUseCase
{
    public function __construct(private readonly AboFollowUpService $followUp)
    {
    }

    /** @return array{plans: list<array<string, mixed>>} */
    public function plans(string $locale): array
    {
        return ['plans' => $this->followUp->plans($locale)];
    }

    /** @return array<string, mixed> */
    public function subscribers(int $planId, ?string $status, int $page, int $limit, string $locale): array
    {
        return $this->followUp->subscribers($planId, $status, $page, $limit, $locale);
    }

    /** @return array<string, mixed> */
    public function subscription(int $id, string $locale): array
    {
        return $this->followUp->subscription($id, $locale);
    }
}
