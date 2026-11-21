<?php

namespace App\Logging;

use App\Services\Privacy\PersonalDataMask;
use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;

/**
 * Lot ABO-4 (décision du chef, 21/11/2026) : un lien d'agenda porte son jeton dans l'adresse (`/api/calendrier/{jeton}.ics`). Il ne paraît
 * JAMAIS dans nos journaux : ce processeur le remplace par `/api/calendrier/***` dans le message et dans le contexte de chaque ligne (le
 * routeur de Symfony journalise l'adresse demandée). Les anomalies (INC) passent par `PersonalDataMask::text`, qui fait de même.
 */
#[AsMonologProcessor]
final class SensitivePathProcessor
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $all = $record->message . ' ' . json_encode([$record->context, $record->extra], JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (!str_contains($all, PersonalDataMask::CALENDAR_PREFIX)) {
            return $record;
        }
        // le jeton reparaît sans le chemin ailleurs dans la ligne (les paramètres de la route, journalisés par le routeur) : remplacé aussi
        preg_match_all('~/api/calendrier/([^/?\s"\'<>#.]+)~', $all, $m);
        $tokens = array_values(array_filter(array_unique($m[1] ?? []), fn (string $t) => strlen($t) >= 16));
        $mask = fn (string $text): string => str_replace($tokens, '***', PersonalDataMask::calendarPaths($text));

        return $record->with(message: $mask($record->message), context: self::walk($record->context, $mask), extra: self::walk($record->extra, $mask));
    }

    /** @param array<mixed> $values @return array<mixed> */
    private static function walk(array $values, callable $mask): array
    {
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $values[$key] = $mask($value);
            } elseif (is_array($value)) {
                $values[$key] = self::walk($value, $mask);
            }
        }

        return $values;
    }
}
