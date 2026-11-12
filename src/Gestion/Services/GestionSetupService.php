<?php

namespace App\Gestion\Services;

use App\Services\Platform\SiteReadinessService;

/**
 * Cheminement ciblé de l'application de gestion (lot CHE, 12/11/2026, page `/admin/gestion`) : les étapes du groupe `app.management` du
 * cheminement du site (SiteReadinessService), un niveau none | todo | ready et, sans module accordé, l'étape « Obtenir » (essai ou demande).
 */
class GestionSetupService
{
    public function __construct(private readonly SiteReadinessService $readiness)
    {
    }

    /** @return array{granted: bool, obtain: ?array<string, mixed>, steps: list<array<string, mixed>>, level: string, levelLabel: string, blockingTodo: int, improvementTodo: int} */
    public function overview(): array
    {
        $all = $this->readiness->forCurrentSite();
        $obtain = array_values(array_filter($all['steps'], fn ($s) => $s['key'] === 'obtain.app.management'));
        $steps = array_values(array_filter($all['steps'], fn ($s) => $s['group'] === 'app.management' && !str_starts_with($s['key'], 'obtain.')));
        $granted = $steps !== [];
        $blocking = count(array_filter($steps, fn ($s) => $s['status'] === 'todo' && ($s['blocking'] ?? true)));
        $improvement = count(array_filter($steps, fn ($s) => $s['status'] !== 'done' && !($s['blocking'] ?? true)));
        $level = !$granted ? 'none' : ($blocking > 0 ? 'todo' : 'ready');

        return ['granted' => $granted, 'obtain' => $obtain[0] ?? null, 'steps' => $steps, 'level' => $level,
            'levelLabel' => ['none' => 'Application de gestion non accordée', 'todo' => 'À compléter', 'ready' => 'Prête'][$level], 'blockingTodo' => $blocking, 'improvementTodo' => $improvement];
    }
}
