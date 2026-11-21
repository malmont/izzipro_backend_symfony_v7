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
        $all = $record->message . ' ' . json_encode([$record->context, $record->extra], JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) . ' ' . self::messages($record->context);
        if (!str_contains($all, PersonalDataMask::CALENDAR_PREFIX)) {
            return $record;
        }
        // le jeton reparaît sans le chemin ailleurs dans la ligne (les paramètres de la route, journalisés par le routeur) : remplacé aussi
        preg_match_all('~/api/calendrier/([^/?\s"\'<>#.]+)~', $all, $m);
        $tokens = array_values(array_filter(array_unique($m[1] ?? []), fn (string $t) => strlen($t) >= 16));
        $mask = fn (string $text): string => str_replace($tokens, '***', PersonalDataMask::calendarPaths($text));

        return $record->with(message: $mask($record->message), context: self::walk($record->context, $mask), extra: self::walk($record->extra, $mask));
    }

    /** Les messages des exceptions du contexte (`json_encode` n'en montre rien) @param array<mixed> $values */
    private static function messages(array $values): string
    {
        $out = '';
        foreach ($values as $value) {
            for ($e = $value instanceof \Throwable ? $value : null; $e !== null; $e = $e->getPrevious()) {
                $out .= $e->getMessage() . ' ';
            }
        }

        return $out;
    }

    /** @param array<mixed> $values @return array<mixed> */
    private static function walk(array $values, callable $mask): array
    {
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $values[$key] = $mask($value);
            } elseif (is_array($value)) {
                $values[$key] = self::walk($value, $mask);
            } elseif ($value instanceof \Throwable) {
                // relecture du 21/11/2026 : l'objet exception (`['exception' => $e]` de l'ErrorListener) est écrit par le formateur avec son
                // message : s'il porte le chemin ou le jeton, il est remplacé par son résumé masqué (classe, message, fichier et ligne)
                $text = '';
                for ($e = $value; $e !== null; $e = $e->getPrevious()) {
                    $text .= $e->getMessage() . "\n";
                }
                if ($mask($text) !== $text) {
                    $summary = [];
                    for ($e = $value; $e !== null; $e = $e->getPrevious()) {
                        $summary[] = sprintf('[object] (%s(code: %s): %s at %s:%d)', $e::class, (string) $e->getCode(), $mask($e->getMessage()), $e->getFile(), $e->getLine());
                    }
                    $values[$key] = implode(' [previous] ', $summary);
                }
            }
        }

        return $values;
    }
}
