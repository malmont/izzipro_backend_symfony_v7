<?php

namespace App\UseCase\TechDashboardUseCase;

use App\Repository\TechMetricRepository;

/**
 * Modification des seuils bon / moyen du tableau de bord technique (T2, 03/11/2026) : chaque mesure reçoit deux nombres positifs,
 * le seuil « bon » ne dépassant pas le seuil « moyen » (direction max). Les mesures inconnues sont ignorées ; une erreur n'enregistre rien.
 */
class UpdateTechThresholdsUseCase
{
    public function __construct(private readonly TechMetricRepository $repository)
    {
    }

    /**
     * @param array<string, array{good?: mixed, warn?: mixed}> $input valeurs saisies, par mesure
     * @return list<string> erreurs (vide si tout est enregistré)
     */
    public function execute(array $input): array
    {
        $known = [];
        foreach ($this->repository->thresholds() as $t) {
            $known[$t['metric']] = $t;
        }
        $errors = [];
        $updates = [];
        foreach ($input as $metric => $values) {
            if (!isset($known[$metric]) || !is_array($values)) {
                continue;
            }
            $good = self::number($values['good'] ?? null);
            $warn = self::number($values['warn'] ?? null);
            $label = $known[$metric]['label'];
            if ($good === null || $warn === null) {
                $errors[] = sprintf('%s : les deux seuils doivent être des nombres positifs.', $label);
                continue;
            }
            $direction = $known[$metric]['direction'] ?? 'max';
            if (($direction === 'max' && $good > $warn) || ($direction === 'min' && $good < $warn)) {
                $errors[] = sprintf('%s : le seuil « bon » doit %s le seuil « moyen ».', $label, $direction === 'max' ? 'rester sous' : 'rester au-dessus de');
                continue;
            }
            if (abs($good - $known[$metric]['good']) > 0.0005 || abs($warn - $known[$metric]['warn']) > 0.0005) {
                $updates[$metric] = [$good, $warn];
            }
        }
        if ($errors !== []) {
            return $errors;
        }
        foreach ($updates as $metric => [$good, $warn]) {
            $this->repository->saveThreshold($metric, $good, $warn);
        }

        return [];
    }

    private static function number(mixed $raw): ?float
    {
        if (!is_scalar($raw)) {
            return null;
        }
        $text = str_replace([' ', ','], ['', '.'], trim((string) $raw));
        if ($text === '' || !is_numeric($text) || (float) $text < 0) {
            return null;
        }

        return (float) $text;
    }
}
